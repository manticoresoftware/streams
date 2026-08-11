#!/bin/bash

if [ -f "/tmp/probe.lock" ]; then
  echo "Probe lock file exist. Skip probe"
  exit 0
fi


if /usr/bin/searchd --status | grep _indexes: | grep pq | grep tests; then
    exit 0
  else
    exit 1
fi
