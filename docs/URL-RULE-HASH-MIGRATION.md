# Repair URL OR-list hashes in existing Streams rules

This is a **one-time migration** for rules stored before the URL-alternative
whitespace fix in PR #8. Upgrading the UI code alone does not recalculate
hashes already stored in percolate (`pq`) tables.

The `url-rules:repair` Artisan command has three phases:

- **Plan (read-only):** scan a stream or all streams, rebuild URLs from
  `tags.originalQuery`, and write a checksum-protected JSONL plan. The plan
  contains a full backup (`id`, `query`, `filters`, `tags`) of every proposed
  replacement, plus records that need manual review. No database writes occur.
- **Apply:** check the plan checksum, use the same MySQL advisory lock as
  `ManticoreService`, compare every live rule with the backup, and issue a
  replicated `REPLACE INTO <stream>_cluster:pq` for rules that are unchanged
  since planning. Preserve IDs, filters, raw tags (including timestamps), and
  related statistics. Verify each replacement by reading it back.
- **Rollback:** restore the backed-up versions, but only if the live rule
  still exactly matches the version installed by the migration. Changes made
  after migration are not overwritten.

Only differences confined to MD5 tokens are repaired automatically. Rules
whose original query is missing, variable-based, or would change in other
ways are logged as `review` and **not modified**. The generated plan contains
these rule IDs and reasons.

## Important safety requirements

1. Deploy the URL rewrite fix **and** this command before running it. Confirm
   the new version has passed CI and been tested in a staging copy.
2. Run during a low-traffic period. Kafka matching can continue, but **avoid
   editing/importing/deleting rules** while the plan is being applied. The
   command takes the normal MySQL advisory lock while writing each stream;
   any direct Manticore writes bypassing the application lock remain unsafe.
3. Keep an external encrypted copy of the JSONL plan *before* applying any
   changes. It contains original queries and metadata and is mode `0600`.
   The pod filesystem is not durable storage.
4. First do a canary (`--max-updates=10`) on staging, then the full apply.
   An interrupted/partial apply can be rerun with the same plan. Do **not**
   generate a new plan to resume an interrupted apply.
5. Review `review` records individually. Successfully applying the rest is
   not evidence that all historical rules have been fixed.
6. Previously missed Kafka events are **not** replayed by this command.

## Run with Kubernetes

Below, `POD` must be the UI pod and its PHP container is `manticoresearch-ui`.
The application working directory in the standard image is `/var/www/html`.
Adapt the namespace and pod to your installation.

```sh
NS=manticore-streams
kubectl -n "$NS" get pods
POD='<your-UI-pod-name>'
PLAN=/tmp/url-rule-repair-2026-11-19.jsonl

# 1. Read-only plan for every stream. Run once; this file must not exist yet.
kubectl -n "$NS" exec "$POD" -c manticoresearch-ui -- \
  php artisan url-rules:repair --all --plan="$PLAN" --batch=200

# 2. Copy the COMPLETE backup out of the pod BEFORE applying anything.
umask 077
kubectl -n "$NS" exec "$POD" -c manticoresearch-ui -- \
  cat "$PLAN" > ./url-rule-repair-backup.jsonl
shasum -a 256 ./url-rule-repair-backup.jsonl  # macOS (or sha256sum on Linux)

# 3. Canary (10 rules). Check production matching, rule counts and errors.
kubectl -n "$NS" exec "$POD" -c manticoresearch-ui -- \
  php artisan url-rules:repair --plan="$PLAN" --apply --max-updates=10 --delay-ms=25

# 4. Apply remaining rules; already fixed rows are skipped.
kubectl -n "$NS" exec "$POD" -c manticoresearch-ui -- \
  php artisan url-rules:repair --plan="$PLAN" --apply --delay-ms=25
```

For a single stream, use `--stream=123` instead of `--all` while generating
its own (new) plan file. This is recommended for larger installations: review
and migrate one stream at a time to keep advisory-lock periods short.

## Inspect the plan

This is newline-delimited JSON containing a `header`, `repair` or `review`
records, and an `end` record with a checksum and counts. Use a JSONL viewer
or `jq` to inspect it *locally*:

```sh
jq -r 'select(.type == "repair") | "stream=\(.stream) id=\(.id)"' url-rule-repair-backup.jsonl
jq -r 'select(.type == "review") | "stream=\(.stream) id=\(.id) reason=\(.reason)"' url-rule-repair-backup.jsonl
```

Compare `query` values for a sample of affected IDs before proceeding. A
nonzero `review` count means manual follow-up is required. Running the plan
creation command twice with the same filename is rejected intentionally.

## Roll back

Use the *same* unmodified JSONL plan that was copied before apply. If the pod
has been replaced, securely copy the saved JSONL file to the new UI pod at
`/tmp/url-rule-repair-2026-11-19.jsonl` before running the command.

```sh
kubectl -n "$NS" exec "$POD" -c manticoresearch-ui -- \
  php artisan url-rules:repair --plan="$PLAN" --rollback --delay-ms=25
```

Rollback intentionally restores the **old broken URL hashes**. It is an
emergency recovery mechanism, not a normal end state. It stops and reports an
error if a rule has changed since the migration; it never overwrites those
changes. To retry after a failure, inspect the reported ID and rerun with the
same plan after resolving the conflict.

## Validate after applying

- Confirm counts of PQ rules are unchanged for each stream, and original IDs,
  filters, and tags are still present.
- Verify the changed rules using real transformed documents and `CALL PQ`,
  including OR matches and exclusions.
- Compare output Kafka topic rates with expected behavior.
- Create a **fresh read-only plan with a different filename** after migrating
  to find rules that were added or changed while the initial scan ran.
- Keep the encrypted plan backup until your retention period expires.

This operation corrects stored rules for **future** incoming documents; it
cannot recreate events that were previously filtered out.
