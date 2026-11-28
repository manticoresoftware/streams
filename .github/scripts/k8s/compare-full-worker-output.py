#!/usr/bin/env python3
"""Compare full Kafka output multisets without losing duplicate records."""
import argparse
import collections
import hashlib
import json
from pathlib import Path


def load(path, expected):
    counts = collections.Counter()
    total = 0
    with Path(path).open(encoding="utf-8") as stream:
        for number, line in enumerate(stream, 1):
            try:
                data = json.loads(line)
            except json.JSONDecodeError as exc:
                raise ValueError(f"{path}: invalid JSON at line {number}: {exc}") from exc
            normalized = json.dumps(data, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
            counts[normalized] += 1
            total += 1
    if total != expected:
        raise ValueError(f"{path}: expected {expected} records, found {total}")
    digest = hashlib.sha256()
    for value, count in sorted(counts.items()):
        for _ in range(count):
            digest.update(value.encode("utf-8"))
            digest.update(b"\n")
    return counts, digest.hexdigest()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--java", required=True)
    parser.add_argument("--go", required=True)
    parser.add_argument("--expected", type=int, default=9418)
    parser.add_argument("--scenario", required=True)
    parser.add_argument("--report", required=True)
    args = parser.parse_args()
    java, java_sha = load(args.java, args.expected)
    go, go_sha = load(args.go, args.expected)
    report = {
        "scenario": args.scenario,
        "records": args.expected,
        "java_sha256": java_sha,
        "go_sha256": go_sha,
        "equal": java == go,
    }
    Path(args.report).write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(f"{args.scenario}: {args.expected} records; Java={java_sha}; Go={go_sha}")
    if java != go:
        missing = java - go
        extra = go - java
        # Limit the diagnostic to counts and digests; output records may be large.
        print(f"DIFFERENT OUTPUT: missing {sum(missing.values())}, extra {sum(extra.values())}")
        raise SystemExit(1)
    print("Output multisets match, including duplicate counts.")


if __name__ == "__main__":
    main()
