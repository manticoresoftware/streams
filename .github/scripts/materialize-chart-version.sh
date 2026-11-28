#!/usr/bin/env bash
set -euo pipefail

chart=${1:-helm-chart/Chart.yaml}
short_sha=${SHORT_SHA:-$(git rev-parse --short HEAD)}
# Purely numeric SemVer pre-release identifiers cannot have leading zeros.
# Preserve the existing tag scheme, but disambiguate rare SHA prefixes such as
# 0679384 (GitHub's abbreviated PR merge commit may start with zero).
if [[ "$short_sha" =~ ^0[0-9]+$ ]]; then
  short_sha="g$short_sha"
fi
token="\$Format:%h\$"

source=$(<"$chart")
stripped=${source//"$token"/}
replacements=$(( (${#source} - ${#stripped}) / ${#token} ))
[ "$replacements" -eq 2 ] || {
  echo "expected two development-version tokens in $chart, found $replacements" >&2
  exit 1
}
printf '%s\n' "${source//"$token"/$short_sha}" > "$chart"
