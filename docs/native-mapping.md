# Go-only worker: mapping and delivery

The default worker image contains Go, the pinned JSLT runtime, and no Java or PHP.

Configured mapping rules unsupported by Go are rejected **at startup**.
A data-dependent mapping/JSLT error stops the batch **without committing its
input Kafka offsets**. The worker exits; an operator must fix the cause before
restarting. There is no DLQ or automatic poison-record skip.

Delivery is **at least once**, not exactly once. Outputs confirmed before an
error or a crash can appear again when the uncommitted batch is replayed.
Downstream consumers must tolerate duplicates.

To check a deployed configuration without reading Kafka or Manticore:

```sh
/usr/src/myapp/streams-worker --audit-mapping
```

The command prints only static support and rule/path **indices**, never
mapping expressions or document contents. It cannot prove runtime parity.
On staging, monitor worker crashes/restarts, readiness and increasing Kafka lag.
