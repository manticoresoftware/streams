# Test PR #12 on staging before merging

The normal PR CI builds and tests six Docker images but does not publish
them. To hand a tested candidate to the staging team, a maintainer can
**manually dispatch the existing CI workflow on the PR branch**. This workflow
already exists on `master`, so the manual action is available before merge.

## Maintainer: publish a staging candidate

1. Confirm PR #12 is open and its branch is `wip/go-worker`.
2. In GitHub Actions → **CI** → **Run workflow**, select branch
   `wip/go-worker` and run it. This branch selection is the explicit
   opt-in to staging publication. **Do not choose `master`**.
3. Alternatively, from a machine authenticated with GitHub CLI:

   ```sh
   gh workflow run ci.yml -R manticoresoftware/streams --ref wip/go-worker
   ```

4. The manual run repeats normal CI tests, image builds, Kubernetes E2E, and
   real-Kafka recovery tests. **Only after all succeed**, its
   `publish-pr-staging` job checks that the source SHA is still the current
   head of PR #12, loads the six images *from that same run's CI artifacts*,
   and pushes those exact images to GHCR.
5. Every image receives the tag `pr-12-<first-12-chars-of-head-SHA>`. The
   job also uploads the workflow artifact `staging-pr-12-<full-SHA>`
   containing a matching Helm chart, `staging-pr-12-*-values.yaml`,
   `README.md` (with exact version and deployment commands), and
   `SHA256SUMS`. Share the successful manual run link with the staging team.

Normal `pull_request`, `push`, and scheduled runs **never** publish PR
images. The existing master-only release/publish jobs are unchanged; this
procedure does not merge the PR or create a production release.

## Staging team: deploy the candidate

Obtain the **successful manual CI run ID** from the maintainer, then download
the staging handoff artifact:

```sh
gh run download RUN_ID \
  -R manticoresoftware/streams \
  --pattern 'staging-pr-12-*' \
  --dir ./streams-pr12-staging
```

The downloaded artifact contains a Helm chart built from the PR commit and a
values override referencing all six matching GHCR image tags. Follow the
version-specific `README.md` for the Helm command, using the **existing**
staging release name, namespace, and configuration. The only Helm difference
from the usual upgrade is passing the provided image values file after
`--reuse-values` to avoid retaining old image tags.

Verify that the UI, Manticore, scaler, rules checker and **existing pipeline
StatefulSets** have picked up the PR images. Existing stream pods may need
reconciliation/restart through your standard procedure after the chart
upgrade.

Test actual PQ and JSLT rules, result parity, restart/SIGKILL and replay,
duplicate output, poison records and growing Kafka lag. Do not reset shared
Kafka offsets or use production topics. If staging fails, roll back using
the team's usual procedure and record the tested SHA and findings.

**Access:** the staging cluster must be able to pull the GHCR image packages.
The manual workflow's chart handoff artifact is retained for 30 days; a
different PR commit must be published again rather than reusing old tags.
