#!/usr/bin/env bash
set -euo pipefail

# Prepare a PR #12 staging Helm bundle. No registry write/build happens here.
sha=${1:?usage: package-pr-staging.sh FULL_COMMIT_SHA OUTPUT_DIRECTORY}
destination=${2:?usage: package-pr-staging.sh FULL_COMMIT_SHA OUTPUT_DIRECTORY}
[[ "$sha" =~ ^[0-9a-f]{40}$ ]] || {
  echo 'Expected a full 40-character lowercase commit SHA' >&2
  exit 1
}

short=${sha:0:12}
tag="pr-12-${short}"
chart_version="32.0.5-pr12.g${short}"
mkdir -p "$destination"
destination=$(cd "$destination" && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

cp -a helm-chart "$work/chart"
SHORT_SHA="pr12.g${short}" bash .github/scripts/materialize-chart-version.sh "$work/chart/Chart.yaml"
# AppVersion is also used by the UI-generated pipeline ConfigMap.
sed -i "s/^appVersion:.*/appVersion: ${tag}/" "$work/chart/Chart.yaml"
grep -Fxq "version: ${chart_version}" "$work/chart/Chart.yaml"
grep -Fxq "appVersion: ${tag}" "$work/chart/Chart.yaml"

values_file="staging-pr-12-${short}-values.yaml"
cat > "$destination/$values_file" <<YAML
# Staging-only image overrides for Manticore Streams PR #12, commit ${sha}.
# Pass after --reuse-values so previously pinned image tags are replaced.
worker:
  image:
    repository: ghcr.io/manticoresoftware/streams/worker
    tag: ${tag}
manticore:
  image:
    repository: ghcr.io/manticoresoftware/streams/manticore
    tag: ${tag}
rulesChecker:
  image:
    repository: ghcr.io/manticoresoftware/streams/rules_checker
    tag: ${tag}
scaler:
  php:
    image:
      repository: ghcr.io/manticoresoftware/streams/scaler
      tag: ${tag}
ui:
  image:
    repository: ghcr.io/manticoresoftware/streams/ui
    tag: ${tag}
  nginx:
    image:
      repository: ghcr.io/manticoresoftware/streams/ui-nginx
      tag: ${tag}
YAML

helm lint "$work/chart" -f "$destination/$values_file" >/dev/null
helm template pr12-staging "$work/chart" -f "$destination/$values_file" \
  --kube-version v1.32.5 > "$work/rendered.yaml"

# Workload images appear directly in Deployment manifests; dynamic pipeline
# image repositories and tags appear as separate fields in process.json.
for component in ui ui-nginx scaler; do
  grep -Fq "ghcr.io/manticoresoftware/streams/${component}:${tag}" "$work/rendered.yaml" || {
    echo "The staging chart does not select ${component}:${tag}" >&2
    exit 1
  }
done
for component in worker manticore rules_checker; do
  grep -Fq "\"repository\": \"ghcr.io/manticoresoftware/streams/${component}\"" "$work/rendered.yaml" || {
    echo "The staging process configuration is missing ${component}" >&2
    exit 1
  }
done
grep -Fq "\"tag\": \"${tag}\"" "$work/rendered.yaml"

helm package "$work/chart" --destination "$destination" >/dev/null
chart_file="streams-${chart_version}.tgz"
mv "$destination/manticoresearch-${chart_version}.tgz" "$destination/$chart_file"

cat > "$destination/README.md" <<EOF
# Manticore Streams PR #12: staging candidate

Source commit: ${sha}
Image tag (all six Streams components): ${tag}
Chart version: ${chart_version}
Source PR: https://github.com/manticoresoftware/streams/pull/12
CI run: https://github.com/manticoresoftware/streams/actions/runs/${GITHUB_RUN_ID:-RUN_ID}

The images were loaded from this same successful CI run's build artifacts,
after Kubernetes E2E and Kafka recovery tests had passed. No image was
rebuilt during staging publication.

STAGING ONLY. Do not deploy these images to production.

Download this bundle from the manual CI workflow run. In your established
staging cluster, use the existing Helm release and existing values:

    helm upgrade RELEASE_NAME ./${chart_file} \\
      --namespace STAGING_NAMESPACE \\
      --reuse-values \\
      -f ${values_file} \\
      --wait --timeout 10m

The values override all six image tags, including the worker and Manticore
tags in the UI-generated pipeline configuration. Verify that existing
pipeline StatefulSets actually use the candidate images; if necessary
follow your normal stream restart/reconciliation procedure.

    kubectl -n STAGING_NAMESPACE get pods -o wide
    kubectl -n STAGING_NAMESPACE get pods -o custom-columns='NAME:.metadata.name,IMAGES:.spec.containers[*].image'
    helm -n STAGING_NAMESPACE history RELEASE_NAME

Test actual PQ/JSLT rules, output parity, restart/SIGKILL behavior, Kafka
lag, duplicates, and poison-record recovery using isolated topics/groups
where appropriate. At-least-once delivery permits duplicates. On mapping
or JSLT errors the worker stops without committing input offsets; there
is no DLQ. Record the exact commit SHA in your test report.

To roll back, identify the previous revision using helm history, then
follow your team's staging rollback procedure, for example:

    helm -n STAGING_NAMESPACE rollback RELEASE_NAME PREVIOUS_REVISION --wait

Do not reset shared Kafka consumer groups or delete production data.
EOF

(
  cd "$destination"
  sha256sum "$chart_file" "$values_file" > SHA256SUMS
)
printf 'Prepared %s (%s) from %s\n' "$chart_file" "$tag" "$sha"
