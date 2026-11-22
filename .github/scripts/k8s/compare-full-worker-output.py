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


def source_offsets(path, missing, extra):
    """Candidate offsets for missing 0011 results, ignoring output metadata."""
    def source_key(serialized):
        data = json.loads(serialized)
        if isinstance(data, dict):
            data.pop("matching_queries", None)
        return json.dumps(data, ensure_ascii=False, sort_keys=True, separators=(",", ":"))

    requested_missing = collections.Counter()
    requested_extra = collections.Counter()
    for value, count in missing.items():
        requested_missing[source_key(value)] += count
    for value, count in extra.items():
        requested_extra[source_key(value)] += count

    missing_offsets, extra_offsets = [], []
    counts = collections.Counter()
    with Path(path).open(encoding="utf-8") as stream:
        for offset, line in enumerate(stream):
            try:
                normalized = json.dumps(json.loads(line), ensure_ascii=False,
                                        sort_keys=True, separators=(",", ":"))
            except json.JSONDecodeError:
                continue
            counts[normalized] += 1
            if counts[normalized] <= requested_missing[normalized]:
                missing_offsets.append(offset)
            if counts[normalized] <= requested_extra[normalized]:
                extra_offsets.append(offset)

    ambiguous = sum(min(count, counts[key]) for key, count in requested_missing.items()
                    if counts[key] > count)
    return missing_offsets, extra_offsets, sum(missing.values()) - len(missing_offsets), ambiguous


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--java", required=True)
    parser.add_argument("--go", required=True)
    parser.add_argument("--expected", type=int, default=9418)
    parser.add_argument("--scenario", required=True)
    parser.add_argument("--input", help="Input JSONL for hashed source offset diagnostics")
    parser.add_argument("--report", required=True)
    args = parser.parse_args()
    java, java_sha, java_total = load(args.java)
    go, go_sha, go_total = load(args.go)
    missing = java - go
    extra = go - java
    equal = java == go and java_total == args.expected and go_total == args.expected
    missing_offsets, extra_offsets, input_not_found, ambiguous_offsets = [], [], 0, 0
    if args.input and (missing or extra):
        missing_offsets, extra_offsets, input_not_found, ambiguous_offsets = source_offsets(
            args.input, missing, extra)

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
        "missing_input_offsets_zero_based": missing_offsets[:100],
        "extra_input_offsets_zero_based": extra_offsets[:100],
        "missing_not_found_in_input": input_not_found,
        "ambiguous_duplicate_source_offsets": ambiguous_offsets,
        "missing_input_offset_range": [min(missing_offsets), max(missing_offsets)] if missing_offsets else [],
        "equal": equal,
    }
    Path(args.report).write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(f"{args.scenario}: expected {args.expected}, Java={java_total}, Go={go_total}; "
          f"sha256 Java={java_sha}, Go={go_sha}")
    if not equal:
        print(f"DIFFERENT OUTPUT: missing from Go {sum(missing.values())}, "
              f"extra in Go {sum(extra.values())}; see hashed discrepancy report")
        if args.input:
            print(f"Source offsets missing from Go (zero-based, first 100): {missing_offsets[:100]}")
            print(f"Unresolved source offsets: {input_not_found}; ambiguous duplicates: {ambiguous_offsets}")
        raise SystemExit(1)
    print("Output multisets match, including duplicate counts.")



if __name__ == "__main__":
    main()
