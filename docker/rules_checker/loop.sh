#!/bin/sh

# Infinite loop to run get_messages.sh every minute
while true; do
    /get_messages.sh
    sleep 60
done