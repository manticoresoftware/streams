#!/usr/bin/env python3
"""Check the comparison tool's candidate-offset diagnostic."""
import collections
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path

SPEC = importlib.util.spec_from_file_location(
    "compare_full_worker_output", Path(__file__).with_name("compare-full-worker-output.py"))
COMPARE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(COMPARE)


class OffsetDiagnosticsTests(unittest.TestCase):
    def test_output_metadata_does_not_hide_input_offset(self):
        docs = [{"id": 11, "title": "a"}, {"id": 12, "title": "b"}]
        output = json.dumps({**docs[1], "matching_queries": [{"queryid": 1}]})
        with tempfile.TemporaryDirectory() as tmp:
            source = Path(tmp) / "input.jsonl"
            source.write_text("\n".join(json.dumps(x) for x in docs) + "\n")
            got = COMPARE.source_offsets(source, collections.Counter({output: 1}),
                                         collections.Counter())
        self.assertEqual(got, ([1], [], 0, 0))

    def test_duplicate_source_is_marked_ambiguous(self):
        doc = {"title": "duplicate", "id": 21}
        output = json.dumps({**doc, "matching_queries": [{"queryid": 2}]})
        with tempfile.TemporaryDirectory() as tmp:
            source = Path(tmp) / "input.jsonl"
            source.write_text(json.dumps(doc) + "\n" + json.dumps(doc) + "\n")
            got = COMPARE.source_offsets(source, collections.Counter({output: 1}),
                                         collections.Counter())
        self.assertEqual(got, ([0], [], 0, 1))

    def test_unresolvable_output_is_reported(self):
        with tempfile.TemporaryDirectory() as tmp:
            source = Path(tmp) / "input.jsonl"
            source.write_text('{"id":1}\n')
            got = COMPARE.source_offsets(source,
                                         collections.Counter({'{"id":2,"matching_queries":[]}': 1}),
                                         collections.Counter())
        self.assertEqual(got, ([], [], 1, 0))


if __name__ == "__main__":
    unittest.main()
