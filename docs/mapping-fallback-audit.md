# Java-to-Go mapping fallback audit

The open Java-to-Go migration is intentionally **not** considered complete just
because the public Kafka fixture runs without calling Java. Four fallback paths
remain in `sources/go/mapping.go`, and they are now tagged independently.

## Machine-readable report

Every CI run of the `worker-tests` job uploads a
`mapping-fallback-audit` artifact containing `report.json`.
The artifact is produced by `TestMappingFallbackAudit` using synthetic
documents, known transformation rules, and the original Java worker as oracle.

Each result contains only a case label, input-shape label, output mode, whether
native mapping or Java fallback was used, rule/path indices, any compile
issues, error-presence booleans and equivalence to Java. **No document content,
mapped output, field values, private JSLT rules, or customer configurations are
written to the report.**

The JSON format is versioned (`schema_version: 1`). `totals` has:
- `cases`, `native`, `java_fallback`, `mismatches`;
- `fallback_reasons`, keyed by one of the reason codes below.

A `java_fallback` case can still be marked `equivalent: true` because Go
called the compatibility bridge. Such cases are **not** proof of native parity.

The fallback reason codes are:

| Code | Trigger | Next step |
| --- | --- | --- |
| `unsupported_rule` | Static mapping rule cannot be compiled natively | Compare parser/selector semantics and compile to a native plan |
| `url_compatibility` | Go URL parsing/hash compatibility cannot produce Java's result | Characterize the Java URI behavior and implement native URL hashing |
| `value_conversion` | Selected JSONPath value cannot be rendered with Java-compatible semantics | Preserve source positions, collection order and number/string conversions |
| `rename_delete` | Native rename-mode removal fails | Implement source-aware selection deletion and Java's partial failure behavior |

The runtime counter is bounded in memory: counts are exact per mapper and
at most 32 sample rule/path locations are retained. A separate list records
statically unsupported rule/path positions. The code does **not** log source
payloads or values, and the Java reference calls made by the test oracle
itself are not classified as runtime fallback.

## Running the audit locally

Build the Java worker JAR and compile `sources/go/compat/StreamsCompat.java`,
then run from `sources/go` with the original Java compatibility classpath:

```sh
STREAMS_COMPAT_CLASSPATH="/path/to/compat:/path/to/KafkaHandler.jar" \
STREAMS_MAPPING_AUDIT_OUTPUT="/tmp/mapping-audit.json" \
go test -count=1 -run '^TestMappingFallbackAudit$' -v .
```

The audit compares Java and Go output documents in the four legacy output
modes `1000`, `0100`, `1010` and `0010`; mismatches cause test failure
even if the report file is successfully generated.

## Exit criteria for deleting Java

1. Extend the synthetic corpus to all documented Jayway constructs and to
   additional error and input-shape combinations.
2. Characterize unsupported mappings against the real Java oracle, then
   remove each runtime fallback by replacing it with a native equivalent.
3. Add golden fixtures from the Java oracle **before** deleting its source.
4. Make the audit fail if any supported configuration requires Java, and
   confirm zero fallbacks and zero differences on the audit, the public Kafka
   fixture, and the additional real-world rules provided for validation.
5. Switch Docker's default runtime to the existing JVM-free target. Remove the
   Java JAR, Maven and Java sources only after all the above gates and full
   Kubernetes E2E, cold-start and resource benchmarks pass.

This audit is a **coverage instrument**, not a claim to exhaustively test all
possible JSON documents or all extensions of Jayway JSONPath. The goal is to
make the remaining gap explicit and shrink it until Java is no longer needed.

## Checking actual production rule sets without production documents

A separate optional test can inspect local configurations **without making
Kafka connections, processing documents, or launching Java**. Create a local
JSON file containing an array of the existing rules and field definitions:

```json
[
  {
    "rules": ["items[*].name => selected"],
    "fields": {"selected": "text"},
    "output": "1000"
  }
]
```

From `sources/go`:

```sh
STREAMS_MAPPING_CONFIG_AUDIT_INPUT=/path/to/local-rules.json \
STREAMS_MAPPING_CONFIG_AUDIT_OUTPUT=/tmp/mapping-config-audit.json \
go test -count=1 -run '^TestMappingConfigurationAudit$' -v .
```

The output contains only profile indices, output modes, counts of rules and
indices of statically unsupported paths. It intentionally does **not** emit
rule expressions, field names or any input document. A successful static
compile is not proof of runtime equivalence; data-dependent JSONPath and URL
behavior must still pass the differential tests described above. Keep local
customer rule files outside the Git repository and CI artifacts.
