#!/usr/bin/env bash
set -euo pipefail

chart=${1:-helm-chart/Chart.yaml}
short_sha=${SHORT_SHA:-$(git rev-parse --short HEAD)}
token="\$Format:%h\$"

source=$(<"$chart")
stripped=${source//"$token"/}
replacements=$(( (${#source} - ${#stripped}) / ${#token} ))
[ "$replacements" -eq 2 ] || {
  echo "expected two development-version tokens in $chart, found $replacements" >&2
  exit 1
}
printf '%s\n' "${source//"$token"/$short_sha}" > "$chart"
