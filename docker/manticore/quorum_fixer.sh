#!/bin/bash

sleep 60

while [ true ]; do
  php /etc/manticoresearch/quorum.php &
  sleep 15
done

