#!/bin/sh

if [ ! -d "/var/lib/manticore/log" ]; then
  mkdir -p "/var/lib/manticore/log"
fi

if [ ! -d "/var/lib/manticore/data" ]; then
  mkdir -p "/var/lib/manticore/data"
fi

if [ ! -d "/var/lib/manticore/replication" ]; then
  mkdir -p "/var/lib/manticore/replication"
fi

if [ ! -d "/var/lib/manticore/plugins" ]; then
  mkdir -p "/var/lib/manticore/plugins"
fi

while [ -z "$(ls -A /var/lib/manticore/)" ]
  do
    echo "Waiting for volume mounts"
    sleep 1;
  done
echo "Work end"

if [ ! -d "/var/lib/manticore/log" ]; then
  mkdir -p "/var/lib/manticore/log"
fi

if [ -n "$QUERY_LOG_TO_STDOUT" ]; then
    ln -sf /dev/stdout /var/log/manticore/query.log
fi

exec searchd --nodetach
