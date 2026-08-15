#!/usr/bin/env bash
set -euo pipefail

kubectl -n "$APP_NAMESPACE" exec "$UI_POD" -c "$UI_CONTAINER" -- sh -c '
  test -x ./vendor/bin/phpunit
  php -d short_open_tag=off ./vendor/bin/phpunit --testsuite Cluster --list-tests --stderr | tee /tmp/cluster-test-list.txt
  grep -Fqx " - Tests\\Cluster\\ClusterTest::assignUser" /tmp/cluster-test-list.txt
  php -d short_open_tag=off ./vendor/bin/phpunit --do-not-cache-result --testsuite Cluster --stderr
'
