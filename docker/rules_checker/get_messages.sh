#!/bin/sh

NAME=$(basename "$0")
ps aux | grep -v grep | grep "$NAME" > /dev/null || exit 1

while true; do
    mysql -h"$MANTICORE_HOST" -P"$MANTICORE_PORT" -e ";" && break
    echo "Wait for Manticore..."
    sleep 10
done

topics=$(echo "$KAFKA_TOPIC" | sed 's/,/ /g')
timeout 120 kcat -b "$KAFKA_HOST" -C -G "$KAFKA_GROUP" $topics | head -c 10m >/storage/messages.dat

if [ $? -eq 0 ]; then
    php /var/www/html/insert.php && rm /storage/messages.dat
fi