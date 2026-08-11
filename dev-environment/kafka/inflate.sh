#!/bin/bash
/opt/bitnami/kafka/bin/kafka-console-producer.sh --broker-list localhost:29092 --topic my-docs < /test_data.json
