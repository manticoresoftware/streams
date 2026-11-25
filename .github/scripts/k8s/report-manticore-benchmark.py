"""Measure the existing K8s fixture on the previous and candidate images."""
import datetime
import json
import os
import pathlib
import statistics
import subprocess
import sys
import time

REPORT = pathlib.Path("manticore-benchmark.json")

def kubectl(*args):
    return subprocess.check_output(["kubectl", "-n", os.environ["APP_NAMESPACE"], *args], text=True).strip()

def pod():
    return json.loads(kubectl("get", "pod", os.environ["PIPELINE"] + "-0", "-o", "json"))

def snapshot(p):
    container = next(c["name"] for c in p["spec"]["containers"] if c["name"].endswith("-manticore"))
    # Use the container's cgroup, not process RSS; current includes page cache.
    raw = kubectl("exec", p["metadata"]["name"], "-c", container, "--", "sh", "-ec", '''
root=/sys/fs/cgroup
rel=$(awk -F: '$1 == "0" { print $3 }' /proc/self/cgroup)
if [ -n "$rel" ] && [ -f "$root$rel/memory.current" ]; then
    root="$root$rel"
fi
cat "$root/memory.current"
awk '$1 == "usage_usec" { print $2 }' "$root/cpu.stat"
''')
    memory, cpu = map(int, raw.splitlines())
    return memory, cpu

def dt(s):
    return datetime.datetime.fromisoformat(s.replace("Z", "+00:00"))

def start():
    p = pod()
    ready = next(c for c in p["status"]["conditions"] if c["type"] == "Ready" and c["status"] == "True")
    container = next(c for c in p["status"]["containerStatuses"] if c["name"].endswith("-manticore"))
    assert container["restartCount"] == 0, "Manticore restarted before benchmark"
    t0 = time.monotonic()
    _, cpu0 = snapshot(p)
    time.sleep(10)
    memory, cpu1 = snapshot(p)
    idle_elapsed = time.monotonic() - t0
    data = {
        "image": container["imageID"],
        "pod_uid": p["metadata"]["uid"],
        "container_id": container["containerID"],
        "restart_count": container["restartCount"],
        "pod_creation_to_ready_s": (dt(ready["lastTransitionTime"]) - dt(p["metadata"]["creationTimestamp"])).total_seconds(),
        "manticore_start_to_pod_ready_s": (dt(ready["lastTransitionTime"]) - dt(container["state"]["running"]["startedAt"])).total_seconds(),
        "idle_memory_mib": memory / 1048576,
        "idle_cpu_mcores": (cpu1 - cpu0) / idle_elapsed / 1000,
        "workload_start_time": time.monotonic(),
        "workload_start_cpu_usec": cpu1,
        "input_records": int(os.environ["FIXTURE_RECORDS"]),
        "expected_output_records": int(os.environ["EXPECTED_RECORDS"]),
    }
    REPORT.write_text(json.dumps(data, indent=2) + "\n")

def finish():
    end = time.monotonic()
    data = json.loads(REPORT.read_text())
    p = pod()
    c = next(c for c in p["status"]["containerStatuses"] if c["name"].endswith("-manticore"))
    assert (p["metadata"]["uid"], c["containerID"], c["restartCount"]) == (data["pod_uid"], data["container_id"], data["restart_count"]), "Manticore restarted during benchmark"
    memory, cpu = snapshot(p)
    elapsed = end - data["workload_start_time"]
    data.update(
        fixture_elapsed_s=elapsed,
        fixture_input_docs_per_s=data["input_records"] / elapsed,
        post_fixture_memory_mib=memory / 1048576,
        fixture_manticore_cpu_s=(cpu-data["workload_start_cpu_usec"])/1e6,
    )
    REPORT.write_text(json.dumps(data, indent=2) + "\n")
    print(REPORT.read_text())

def summary():
    variants = ("reference", "candidate")
    data = {
        variant: [
            json.loads(pathlib.Path("benchmark", f"manticore-benchmark-{variant}-{i}", REPORT.name).read_text())
            for i in range(1, 4)
        ]
        for variant in variants
    }
    all_runs = data["reference"] + data["candidate"]
    assert len({r["input_records"] for r in all_runs}) == 1
    assert len({r["expected_output_records"] for r in all_runs}) == 1
    for runs in data.values():
        assert len({r["image"] for r in runs}) == 1, "Image changed between repetitions"
    lines = [
        "## Manticore image runtime comparison",
        "",
        "Median of three repetitions per variant; ranges in parentheses.",
        "",
        "| Metric | Previous slim | Current slim |",
        "| --- | ---: | ---: |",
    ]
    for name, key in (
        ("Pod creation to ready (s)", "pod_creation_to_ready_s"),
        ("Manticore container start to pod ready (s)", "manticore_start_to_pod_ready_s"),
        ("Idle container memory (MiB)", "idle_memory_mib"),
        ("Idle CPU (millicores)", "idle_cpu_mcores"),
        ("Fixture input records/s", "fixture_input_docs_per_s"),
        ("Fixture publish to verified output (s)", "fixture_elapsed_s"),
        ("Manticore CPU during fixture (s)", "fixture_manticore_cpu_s"),
        ("Post-fixture memory (MiB)", "post_fixture_memory_mib"),
    ):
        def fmt(variant):
            values = [r[key] for r in data[variant]]
            return f"{statistics.median(values):.2f} ({min(values):.2f}–{max(values):.2f})"
        lines.append(f"| {name} | {fmt('reference')} | {fmt('candidate')} |")
    lines += [
        "",
        f"Each run verified {all_runs[0]['expected_output_records']} Kafka output records from {all_runs[0]['input_records']} fixture inputs.",
        "Each repetition runs on a separate GitHub-hosted runner of the same type. Images are preloaded; startup includes other pod containers and Kubernetes scheduling. Throughput includes fixture publication and output polling, not isolated steady-state PQ throughput. Idle memory is cgroup usage including cache; idle CPU is sampled for about ten seconds. These measurements are indicative, not a controlled performance gate.",
    ]
    report = "\n".join(lines) + "\n"
    print(report)
    with open(os.environ["GITHUB_STEP_SUMMARY"], "a") as f:
        f.write(report)


if __name__ == "__main__":
    {"start": start, "finish": finish, "summary": summary}[sys.argv[1]]()
