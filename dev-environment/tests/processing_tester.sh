#!/bin/bash

# Processed: 43403 Matched: 59 Queried: 43403
# Processed: 43403 Matched: 9418 Queried: 43403

# /opt/bitnami/kafka/bin/kafka-topics.sh --bootstrap-server=localhost:9092 --list


cd .. && docker-compose down && docker-compose up -d || exit 0

echo -e "\e[32mDocker compose up to date\e[0m"


ROOT_PATH="$(pwd)/../sources"
cd $ROOT_PATH/src/ && mvn clean compile assembly:single
cd $ROOT_PATH/../docker/worker/
rm $ROOT_PATH/../docker/worker/KafkaHandler.jar
cp $ROOT_PATH/src/target/KafkaPublisher-1.0-SNAPSHOT-jar-with-dependencies.jar $ROOT_PATH/../docker/worker/KafkaHandler.jar

docker exec -it manticore mysql -h0 -P9306 -e "TRUNCATE TABLE m1_cluster:pq"

# @url http://www.xiaomi.com
# @url http://blog.sina.com.cn/liyunjie999
# @url http://blog.sina.com.cn/cosmopolitan2008
docker exec -it manticore mysql -h0 -P9306 -e "insert into m1_cluster:pq (query) values ('经'), ('小'), ('集'), ('喜'), ('@url_host_path 4d236d9a2d102c5fe6ad1c50da4bec50 be988a6134bc8254465424e5a70ef037 001f3bba441b1b4cc27a8b2178089de0'), ('@url_host_path 7efdfc94655a25dcea3ec85e9bb703fa aa42331b8d01a7f20fb4694df167a081 6eca4c3d7807437cc2093c27ab8d527d 66d8ed1485517a9fab70899be38f3c59 ae6d61225c9f5f5976002f665f500f3d'), ('@url_host_path 7efdfc94655a25dcea3ec85e9bb703fa aa42331b8d01a7f20fb4694df167a081 6eca4c3d7807437cc2093c27ab8d527d 66d8ed1485517a9fab70899be38f3c59 f6df891e7039c231ca589dadaaf3fb49');"
#docker exec -it manticore mysql -h0 -P9306 -e "insert into m1_cluster:pq (query) values ('@url_host_path 4d236d9a2d102c5fe6ad1c50da4bec50 be988a6134bc8254465424e5a70ef037 001f3bba441b1b4cc27a8b2178089de0'), ('@url_host_path 7efdfc94655a25dcea3ec85e9bb703fa aa42331b8d01a7f20fb4694df167a081 6eca4c3d7807437cc2093c27ab8d527d 66d8ed1485517a9fab70899be38f3c59 ae6d61225c9f5f5976002f665f500f3d'), ('@url_host_path 7efdfc94655a25dcea3ec85e9bb703fa aa42331b8d01a7f20fb4694df167a081 6eca4c3d7807437cc2093c27ab8d527d 66d8ed1485517a9fab70899be38f3c59 f6df891e7039c231ca589dadaaf3fb49');"

docker exec -it kafka /opt/bitnami/kafka/bin/kafka-topics.sh --bootstrap-server=localhost:9092 --create --topic my-results --partitions=1 --replication-factor=1
docker exec -it kafka /opt/bitnami/kafka/bin/kafka-topics.sh --bootstrap-server=localhost:9092 --create --topic my-docs --partitions=1 --replication-factor=1

cd $ROOT_PATH/../dev-environment


export LABEL="m1" OUTPUT_DOCS="0011" \
TRANSFORM_RULES="whole_document => json|text.status.retweeted_status.user.url&&text.comment.status.user.url&&text.comment.status.retweeted_status.user.url&&text.comment.reply_comment.user.url&&text.comment.reply_comment.text&&text.comment.status.statusurl&&text.status.statusurl&&text.comment.user.url&&text.comment.reply_comment.status.user.url&&text.comment.source => url|text.comment.status.source => source|text.comment.status.text => text|text.comment.text => title|text.status.text => statustext" \
MANTICORE_FIELDS="json=json|url=url|text=source|text=text|text=title|text=statustext" LOG_LEVEL="WARN" SUSPEND="0" SCALER_HOST="localhost:8808" \
KAFKA_INPUT_HOST="localhost:29092" KAFKA_OUTPUT_HOST="localhost:29092" MANTICORE_HOST="localhost:9306" \
KAFKA_INPUT_TOPIC="my-docs" KAFKA_OUTPUT_TOPIC="my-results" KAFKA_GROUP_NAME="streams-manticore" MAX_THREADS="1" \
PROCESSED_MEASURE_TIME="60" MAX_BATCH_SIZE="5000" METRICS_STORAGE_HOST="localhost" METRICS_STORAGE_PORT="8123" DEBUG_PROCESSING=1



timeout 120 java -jar ../docker/worker/KafkaHandler.jar &

sleep 10

docker exec -it kafka bash -c /inflate.sh

echo -e "\e[32mFinish sending data to Kafka\e[0m \n"

sleep 120

docker exec kafka bash -c '/opt/bitnami/kafka/bin/kafka-run-class.sh kafka.tools.GetOffsetShell --broker-list localhost:9092 --topic my-results'
echo "Finish"
