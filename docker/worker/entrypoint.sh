#!/bin/bash

if [ -z "$MAX_RAM" ]
then
MAX_RAM="8G"
fi

MAX_RAM=$(numfmt --from=iec $MAX_RAM)

echo "Max ram size set as $MAX_RAM"
java -jar -XX:MaxRAM=$MAX_RAM -XX:MaxHeapFreeRatio=50 -XX:+UnlockExperimentalVMOptions -XX:+UseG1GC KafkaHandler.jar
