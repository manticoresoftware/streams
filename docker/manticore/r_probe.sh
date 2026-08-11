#!/bin/bash

#CLUSTER_STATUS=$(/usr/bin/mysql -h0 -P$MANTICORE_PORT -e "show status like 'cluster_${PIPELINE}_cluster_status' \G" | grep Value | cut -d" " -f4)
#if [[ $CLUSTER_STATUS == "non-primary" ]]; then
#  echo "Cluster status not in primary state"
#  exit 1
#fi

if /usr/bin/mysql -h0 -P$MANTICORE_PORT -e "show tables\G" | grep Table | cut -d" " -f2 | grep -w pq; then
    exit 0
  else
    exit 1
fi
