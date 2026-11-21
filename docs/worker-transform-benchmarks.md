# Worker Go / Java transformation microbenchmarks

These are **isolated, warmed transformation** measurements for the [Java-to-Go migration](https://github.com/manticoresoftware/streams/pull/12). They are **not** an end-to-end Kafka/Manticore throughput benchmark and must not be presented as one.

## What is measured

- `BenchmarkWorkerFixtureMapping`: 1,010+ evenly spaced records from the repository's public 43,403-record Kafka fixture, using exactly the same mapping configuration as `TestFixtureMappingFallbacks` (`OUTPUT_DOCS=0011`). Native Go mapping is compared with a **persistent** Java `StreamsCompat` process; startup and configuration parsing happen before the timer.
- `BenchmarkWorkerJSLT`: the same 32 synthetic JSON documents (Unicode, HTML cleanup, URL extraction, integer passthrough) processed with the same compiled JSLT expression. Native Goja and a persistent Java JSLT transformer are initialized before timing.

Go runs inside the test process. The Java reference runs in a separate, warmed JVM over a JSON-lines pipe. **Java timing includes IPC/serialization overhead**; therefore the benchmark measures the migration's current callable transformer paths, *not pure engine performance*. `-benchmem` reports allocations of the Go test process, **not** Java heap allocations. Never compare those allocation numbers as if they represented JVM memory.

The separate benchmark workflow runs three measurements per scenario at 2 seconds each, saves the raw output to the `worker-transform-benchmarks` artifact and adds it to the job summary. It also profiles native Goja CPU and allocations with a 5-second JSLT-only run and saves the profile data and hot-spot tables as a separate artifact. Compare results on the same runner, under similar load. No private production JSLT expressions or Kafka documents are included.

## Run locally

From the repository root, with Java 21, Go from `sources/go/go.mod`, Maven, and `curl`:

```bash
set -euo pipefail
ROOT="$PWD"
mvn -B -q -DskipTests -f sources/src/pom.xml compile assembly:single
worker_jar=$(find sources/src/target -maxdepth 1 -name '*-jar-with-dependencies.jar' -print -quit)
test -n "$worker_jar"
mkdir -p /tmp/streams-bench-compat
javac -cp "$worker_jar" -d /tmp/streams-bench-compat sources/go/compat/StreamsCompat.java
curl -fsSL --retry 3 https://cdn.jsdelivr.net/npm/jslt-js@0.1.8/dist/jslt-bundle.js -o sources/go/jslt-bundle.js
printf '%s  %s\n' ce1c69ad5dd4f7e82a69b7fa0b13926266606e3f3a4abd666f407c1e64dbb365 sources/go/jslt-bundle.js | sha256sum -c -
cd sources/go
STREAMS_COMPAT_CLASSPATH="/tmp/streams-bench-compat:$ROOT/$worker_jar" \
STREAMS_JSLT_BUNDLE="$ROOT/sources/go/jslt-bundle.js" \
go test -run '^$' -bench '^BenchmarkWorker' -benchtime=2s -count=3 -benchmem -timeout=10m .
```

For production performance decisions, also compare *two complete worker containers* on the same Kafka, Manticore and JSLT workload, recording cold image-pull startup, end-to-end documents/s, process RSS/peak RSS, JVM heap and Go live heap, and output equivalence. The existing Kubernetes E2E uses preloaded images and currently tests only the JVM-free candidate.
