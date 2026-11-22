#!/usr/bin/env python3
"""Aggregate measured worker runs; avoid interpreting microbenchmarks as E2E."""
import csv
import math
import statistics
import sys
from collections import defaultdict


def fmt(value, suffix=""):
    if value is None or math.isnan(value):
        return "n/a"
    return f"{value:.2f}{suffix}"


def median(rows, key):
    vals = [float(r[key]) for r in rows if r.get(key) not in ("", "nan", None)]
    return statistics.median(vals) if vals else float("nan")


def main(path):
    with open(path, newline="", encoding="utf-8") as handle:
        data = list(csv.DictReader(handle))
    if not data:
        raise SystemExit("no completed runs")
    groups = defaultdict(list)
    for row in data:
        groups[(row["scenario"], row["batch_size"], row["implementation"])].append(row)
    print("# Java vs JVM-free Go full-worker benchmark")
    print()
    print("Same K3s/Kafka/Manticore/PQ workload; one worker at a time.")
    print("Preloaded input backlog; images are already imported into K3s (no network image-pull timing).")
    print("Throughput uses input count / time from worker container start to final output, including initialization.")
    print("First-output latency includes Kafka group assignment and initial percolator processing.")
    print("Each row is the median of the completed repetitions. CPU time is the worker container's cgroup usage.")
    print()
    print("| Scenario | Max batch | Input docs | Output records | Worker | Repeats | Wall (s) | Documents/s | First output (s) | Peak RSS (MiB) | CPU (s) | Heap (MiB) |")
    print("|---|---:|---:|---:|---|---:|---:|---:|---:|---:|---:|---:|")
    for (scenario, batch, implementation), rows in sorted(groups.items()):
        print(f"| {scenario} | {batch} | {rows[0]['input_docs']} | {rows[0]['output_records']} | {implementation} | {len(rows)} | "
              f"{fmt(median(rows, 'wall_s'))} | {fmt(median(rows, 'docs_per_s'))} | "
              f"{fmt(median(rows, 'first_s'))} | {fmt(median(rows, 'peak_rss_mib'))} | "
              f"{fmt(median(rows, 'cpu_s'))} | {fmt(median(rows, 'heap_mib'))} |")
    print()
    print("Output validity: every completed Java/Go pair was compared as a multiset of canonical JSON records,")
    print("including multiplicity; the benchmark fails on missing, extra, or different records.")
    print()
    print("**Caveats:** The original Java worker is started via manticore.Main with the same environment")
    print("and resource limits as the Go worker. This benchmark does not measure cold image pulls;")
    print("CPU/RSS are cgroup and /proc metrics, not JVM heap allocations.")
    print("No production customer records or JSLT expressions are used.")


if __name__ == "__main__":
    main(sys.argv[1])
