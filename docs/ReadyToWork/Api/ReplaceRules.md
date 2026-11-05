# Replace rules

Use `POST /api/manager/rules/replace` to modify an existing rule. This is **not** the same request shape as `/api/manager/rules/add`: `replace` takes selectors at the top level and the desired values inside `newData`. Adding an identical query and filters is not an update operation; with duplicate checking enabled, `add` leaves the existing rule unchanged.

Pass an `Authorization` header containing a Bearer token and set `Content-Type: application/json`. Optionally pass `streamId` at the top level to select a stream, as with the other manager API routes.

## Select the rule

Use the existing rule's **top-level `id`** to target one rule. Retrieve it first with `/api/manager/rules/searchExtended` if necessary. `newData.id` does **not** select the target.

> **Warning — bulk update:** `replace` applies `newData` to **all records found by the top-level selectors**, not just the first one. Top-level `query`, `filters`, `tags`, `weakQuery`, and `weakTags` can match multiple rules, even when query and filters are combined. With no selector, the search can return rules across the stream. **We recommend updating only by one top-level `id`.** If you only know the query or filters, search for the rule first, verify the intended match, and then replace it by ID.

Top-level `query` and `filters` refer to the **old** values when used as selectors; the values inside `newData` are replacements.

## Set the new values

`newData` must be a JSON object. Its supported fields are:

| Field | Effect |
|---|---|
| `query` | Replace the query when nonempty. |
| `filters` | Replace the filters when nonempty. |
| `tags` | A **JSON-encoded string** containing rule metadata, not a nested JSON object. Its `tag` property replaces the user tag when nonempty; `externalQuery` replaces the external query when nonempty. |

The `tags` metadata also contains `highlighting`. **Always carry over the current `highlighting` value**, even if changing only the tag: the current implementation sets highlighting to `false` if the replacement metadata omits it. The safest tag-only update is to fetch the rule, parse its `tags` string, change the `tag` property, and serialize the metadata back to a string. Empty `query`, `filters`, `tag`, and `externalQuery` values do not clear existing values through this endpoint.

For example, after `/api/manager/rules/searchExtended` returns a rule in `data[0]`:

```js
const rule = searchResponse.data[0];
const metadata = JSON.parse(rule.tags);
metadata.tag = 'new tag';
const payload = {
  id: Number(rule.id),
  newData: { tags: JSON.stringify(metadata) }
};
// POST payload as JSON to /api/manager/rules/replace
```

The equivalent HTTP request, **assuming the current highlighting value is `true`**, is:

```sh
curl -sS -X POST "$BASE_URL/api/manager/rules/replace" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  --data '{"id":123,"newData":{"tags":"{\"tag\":\"new tag\",\"highlighting\":true}"}}'
```

For other rules, use their actual `id` and current metadata rather than copying the example's `highlighting` value. To replace filters instead, send `{"id":123,"newData":{"filters":"new filter expression","tags":"{\"highlighting\":true}"}}`, again retaining the actual highlighting value.

## Match by old query and filters

If the caller does not know the ID, it can select by the **old** query and filters instead:

```json
{
  "query": "old query",
  "filters": "a>0",
  "newData": {
    "query": "abc",
    "filters": "a>0",
    "tags": "{\"tag\":\"new tag\",\"highlighting\":true}"
  }
}
```

Here the top-level `query` and `filters` select rules matching the old values; `newData.query` sets the new query, `newData.tags` sets the new tag and explicitly enables highlighting. `newData.filters` repeats the existing filter and can be omitted if it should stay unchanged. The query selector is an exact match by default (`weakQuery` defaults to `false`), and the filter selector matches the stored filter value.

**This is not a single-rule upsert:** multiple rules matching both selectors can be updated, and `replace` does not run `add`'s duplicate check. If `abc` with `a>0` already exists, changing another rule to that pair can leave duplicate rules. Check the matches with `searchExtended` before replacing, or use a known `id` to target exactly one rule. Use the rule's actual `highlighting` value instead of the example's `true` if highlighting must remain unchanged.

On success the response is HTTP 200 with `{"message":"Rules updated"}`. A missing or non-object `newData` returns HTTP 422; no matching rules currently return HTTP 503 with `{"message":"Update list are empty"}`. Read the rule back through `searchExtended` by ID to verify the stored values.
