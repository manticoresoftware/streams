# CI scripts

This directory contains helpers owned by GitHub Actions workflows.

- `k8s/` contains K3s E2E orchestration and diagnostics invoked by `.github/workflows/ci.yml`.
- `materialize-chart-version.sh` materializes the source-archive chart version for CI packaging and releases.

The developer-run local K3d/K3s harness may reuse these CI helpers; its entry points remain in `dev-environment/`.
