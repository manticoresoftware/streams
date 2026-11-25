"""Measure the existing K8s fixture on the previous and candidate images."""
import datetime
import json
import os
import pathlib
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
        "workload_start_time": time.time(),
        "workload_start_cpu_usec": cpu1,
        "input_records": int(os.environ["FIXTURE_RECORDS"]),
        "expected_output_records": int(os.environ["EXPECTED_RECORDS"]),
    }
    REPORT.write_text(json.dumps(data, indent=2) + "\n")

def finish():
    end = time.time()
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
    data = [json.loads(pathlib.Path("benchmark", "manticore-benchmark-" + variant, REPORT.name).read_text()) for variant in ("reference", "candidate")]
    assert data[0]["input_records"] == data[1]["input_records"]
    assert data[0]["expected_output_records"] == data[1]["expected_output_records"]
    lines = ["## Manticore image runtime comparison", "", "| Metric | Previous slim | Current slim |", "| --- | ---: | ---: |"]
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
        lines.append(f"| {name} | {data[0][key]:.2f} | {data[1][key]:.2f} |")
    lines += ["", "One existing Kafka E2E fixture per variant on separate GitHub-hosted runners of the same type. Images are preloaded; startup includes other pod containers and Kubernetes scheduling. Throughput includes fixture publication and output polling (not an isolated steady-state PQ benchmark). Idle memory is cgroup usage including cache, and idle CPU is sampled over about ten seconds. Treat performance numbers as indicative, not a controlled regression threshold."]
    report = "\n".join(lines) + "\n"
    print(report)
    with open(os.environ["GITHUB_STEP_SUMMARY"], "a") as f:
        f.write(report)

if __name__ == "__main__":
    {"start": start, "finish": finish, "summary": summary}[sys.argv[1]]()
