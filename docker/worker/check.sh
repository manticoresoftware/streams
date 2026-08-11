#!/bin/sh
if [ $(ps -ef | grep -v grep | grep KafkaHandler.jar | wc -l) -lt 1 ]; then
  exit 1
else
  exit 0
fi
