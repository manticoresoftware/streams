# Java vs Go full-worker Kubernetes benchmark (WIP)

This is an opt-in, **full Kafka → JSON mapping → Manticore CALL PQ → optional JSLT → Kafka output** benchmark. It uses the same K3s cluster and UI-generated PQ rules as the existing Kubernetes E2E. The normal CI runs separately.

## Which workers are compared?

- **Java:** the **original Java source** (entry point `manticore.Main`), compiled into `KafkaHandler.jar` from `sources/src` with its dependencies, running on Temurin Java 21 with the PHP 8.5 runtime required by the legacy mapping code. Its image is built from the existing `compat-test` Docker stage, *not* from the hybrid Go entrypoint.
- **Go:** the actual JVM-free `native-runtime` Alpine image, with `STREAMS_NATIVE_ONLY=1`.

Only one implementation runs at a time. The benchmark scales the same Kubernetes StatefulSet to zero, replaces its worker image/command and input/output topic environment, then scales it to one. The co-located Manticore and its persistent PQ table remain the same. Each run uses a **fresh Kafka input topic, output topic and consumer group**.

Both implementations use the same worker resource limits (1 CPU, 1 GiB), output mode `0011`, Manticore/PQ data, topic partition count (1), and identical Kafka fixture. The Java command uses `-XX:MaxRAMPercentage=60.0` so the JVM can use up to about 614 MiB of heap within the unchanged 1 GiB container memory limit. With the default container-aware JVM heap limit (~25%), the original Java implementation crashed with `OutOfMemoryError` inside the `lowerPHP()` string-reading path on a large batch. This setting changes the **benchmark JVM heap ceiling only**, not Java source code or the worker container limits; the remaining memory is needed for native allocations and the PHP child process. It should be reported when comparing memory consumption.

The Kafka group is initialized and reset to offset zero before starting the worker, so the complete backlog is present at startup. For max batch 5,000, all 43,403 public input documents must be committed and exactly 9,418 output messages produced. For max batch 2, the test uses the **first 1,000 public fixture documents** (configurable by `BENCH_SMALL_INPUT_DOCS`) to keep 24 full worker runs within a bounded CI time. The expected output count for that smaller workload is learned from the original Java worker, then Go must produce an identical JSON multiset. Thus **do not directly compare absolute throughput across batch sizes**: the two batch sizes have different input workloads.

## Scenarios

The smoke benchmark on PR updates uses one Java/Go pair at max batch size 2 on a 1,000-document prefix, both with and without JSLT. Applying the `benchmark-full-workers` PR label triggers **three alternating-order pairs per case**, running the small-batch cases first:

| Case | Max batch size | Input docs | JSLT |
| --- | ---: | ---: | --- |
| Large batch / mapping only | 5,000 | 43,403 | No |
| Large batch / JSLT | 5,000 | 43,403 | Yes |
| Small batch / mapping only | 2 (initial batch size 1) | 1,000 | No |
| Small batch / JSLT | 2 (initial batch size 1) | 1,000 | Yes |

The JSLT uses **synthetic** `replace()` and `parse-url()` transformations over the E2E output; no real customer expression or Kafka document is committed.

The Java consumer's default `KAFKA_MAX_POLL_RECORDS=500` can silently increase the effective processing batch to 500 even when `MAX_BATCH_SIZE=2`. In the benchmark the poll limit is therefore capped at `min(MAX_BATCH_SIZE, 500)` for **both** workers, so max-batch-2 runs actually process at most two Kafka records per poll. Go now mirrors Java's separate `max.poll.records` and active batch threshold: it fetches up to the poll limit (subject to hard max-batch capacity) and may increase its active batch size to the number of records returned. Previously Go always fetched at most the initial half-max batch target (one record at `MAX_BATCH_SIZE=2`), creating smaller PQ requests than Java and potentially different match sets. Without this safeguard a previous comparison yielded 160 Java vs 134 Go outputs, but used different effective batch sizes and was invalid for parity conclusions.

Java and Go alternate their execution order across repetitions to reduce the effect of cache warming. For each pair, a Python script canonicalizes every output JSON record and compares **multisets**, including duplicate counts (not only topic offsets or hashes). Any mismatch is a test failure. If the output counts differ, the benchmark saves both totals, multiset differences and example SHA-256 fingerprints of unmatched documents, without uploading raw Kafka payloads. For plain-mode small-batch failures, it additionally records the **zero-based input offsets** of missing records, so a nondeterministic loss of matched documents can be distinguished from a mapper mismatch. The CI mapping audit compares every document in that same 1,000-document prefix against the Java mapper (plus samples of the remaining public fixture).

## Metrics

- Elapsed time from the new worker container's Kubernetes `startedAt` timestamp to completion of the Kafka output topic, including assignment/startup and processing.
- Time to the first output message.
- Effective documents per second = 43,403 / total measured elapsed time.
- Current and peak process RSS from `/proc/1/status`.
- Total cgroup CPU usage from `/sys/fs/cgroup/cpu.stat`, which includes child processes.
- Sampled live-heap gauge (Java and Go use the same metric name).

Output is `artifacts/full-worker-benchmark/measurements.csv`, `summary.md` and one exact-parity checksum report per Java/Go pair. The GitHub Actions job summary and artifact retain those files. On failure, Kubernetes diagnostics are uploaded.

**Limitations:** Images are preloaded into K3s; this benchmark does *not* measure image download/cold pull time. Startup measurements use Kafka topic polling (around 1-second resolution). The Java and Go implementations have different Kafka client libraries and initialization; the result measures the **complete worker path**, not pure JSLT or SQL execution. The sampled heap gauge is not directly comparable to RSS. Differences in output must be resolved before interpreting throughput. The Github Actions runner is shared by the benchmarking process and the Kafka/Manticore containers; repeat measurements before making production recommendations.

## Running

The workflow `.github/workflows/full-worker-benchmark.yml` is deliberately separate from the normal CI. It runs once on PR changes to its implementation files, with one repetition and the large-batch modes. For a full manual run, use its `workflow_dispatch` inputs (`repetitions=3`, `batch_sizes='2 5000'`, `scenarios=all`) once the workflow is available on the default branch. During the WIP PR, changing the benchmark workflow/scripts triggers a new smoke run. To run all four cases three times without merging the PR, add the `benchmark-full-workers` label to the PR. This is an expensive job (12 Java/Go pairs, 24 worker runs); subsequent PR pushes cancel it. The smaller-input case still checks committed offsets and full output parity, not only throughput.

Do **not** promote the JVM-free image to the default, merge the PR or claim production parity based on these measurements alone. Cross-check actual outputs, available memory headroom and customer JSLT compatibility.

## Diagnosing batch-dependent matches

A Kafka group reaching the same committed offset does not guarantee the
workers submitted the same sequence of percolator batches. For diagnostic
runs we capture counts per processed batch (without SQL or JSON values).
In plain small-batch mode, output comparisons strip the added
`matching_queries` metadata before finding candidate source offsets.
When source JSON repeats, an offset is not necessarily uniquely identifiable.

These diagnostic logs introduce minor measurement overhead, so final
throughput should be repeated without this instrumentation after parity is
established.
