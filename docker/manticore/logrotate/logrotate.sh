#!/bin/bash

while [ true ]; do
  /usr/sbin/logrotate /etc/logrotate.d/manticore  --state /tmp/logrotate-state --verbose
  sleep 60
done
