#!/usr/bin/env python3
"""Compare full Kafka output multisets without losing duplicate records."""
import argparse
import collections
import hashlib
import json
from pathlib import Path


def load(path):
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
    digest = hashlib.sha256()
    for value, count in sorted(counts.items()):
        for _ in range(count):
            digest.update(value.encode("utf-8"))
            digest.update(b"\n")
    return counts, digest.hexdigest(), total


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--java", required=True)
    parser.add_argument("--go", required=True)
    parser.add_argument("--expected", type=int, default=9418)
    parser.add_argument("--scenario", required=True)
    parser.add_argument("--report", required=True)
    args = parser.parse_args()
    java, java_sha, java_total = load(args.java)
    go, go_sha, go_total = load(args.go)
    missing = java - go
    extra = go - java
    equal = java == go and java_total == args.expected and go_total == args.expected

    # The records in this public-fixture comparison can be large. Write
    # anonymous hashes and counts, never raw output documents, to CI artifacts.
    def fingerprints(counter):
        return [
            {"sha256": hashlib.sha256(doc.encode("utf-8")).hexdigest(), "count": count}
            for doc, count in sorted(counter.items())[:10]
        ]

    report = {
        "scenario": args.scenario,
        "expected_records": args.expected,
        "java_records": java_total,
        "go_records": go_total,
        "java_sha256": java_sha,
        "go_sha256": go_sha,
        "missing_from_go": sum(missing.values()),
        "extra_in_go": sum(extra.values()),
        "missing_fingerprints": fingerprints(missing),
        "extra_fingerprints": fingerprints(extra),
        "equal": equal,
    }
    Path(args.report).write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(f"{args.scenario}: expected {args.expected}, Java={java_total}, Go={go_total}; "
          f"sha256 Java={java_sha}, Go={go_sha}")
    if not equal:
        print(f"DIFFERENT OUTPUT: missing from Go {sum(missing.values())}, "
              f"extra in Go {sum(extra.values())}; see hashed discrepancy report")
        raise SystemExit(1)
    print("Output multisets match, including duplicate counts.")



if __name__ == "__main__":
    main()
