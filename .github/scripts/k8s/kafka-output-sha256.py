#!/usr/bin/env python3
"""Hash the canonical Kafka JSON multiset, preserving duplicate records."""

import argparse
import collections
import hashlib
import json
from pathlib import Path


def output_digest(path, expected):
    # Keep the canonicalization identical to the historical Java/Go comparison
    # (full-worker-benchmark.yml, run 44969712864).
    counts = collections.Counter()
    with Path(path).open(encoding="utf-8") as stream:
        for number, line in enumerate(stream, 1):
            try:
                data = json.loads(line)
            except json.JSONDecodeError as exc:
                raise ValueError(f"invalid Kafka output JSON at line {number}: {exc}") from exc
            normalized = json.dumps(data, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
            counts[normalized] += 1
    total = sum(counts.values())
    if total != expected:
        raise ValueError(f"expected {expected} Kafka output records, found {total}")
    digest = hashlib.sha256()
    for value, count in sorted(counts.items()):
        encoded = value.encode("utf-8") + b"\n"
        for _ in range(count):
            digest.update(encoded)
    return digest.hexdigest()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("output", type=Path)
    parser.add_argument("expected_records", type=int)
    args = parser.parse_args()
    try:
        print(output_digest(args.output, args.expected_records))
    except (OSError, ValueError) as exc:
        parser.exit(1, f"{exc}\n")


if __name__ == "__main__":
    main()
