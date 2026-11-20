FROM golang:1.26-bookworm@sha256:dc40a13b61304647ed7e5d581d246b86413dfc29d63c6d018310feb93cd98fc0 AS go-build
WORKDIR /build
COPY sources/go/go.mod sources/go/go.sum ./
RUN go mod download
COPY sources/go/ ./
RUN CGO_ENABLED=0 go build -trimpath -o /streams-worker .

FROM go-build AS go-test
RUN CGO_ENABLED=0 go test -c -o /worker.test .

FROM maven:3.9-eclipse-temurin-21@sha256:99e61abcff91a9b1333463bd8451fb18495d6eba9250ac66a338b518f8278320 AS compat-build
WORKDIR /build
COPY sources/src/pom.xml ./
COPY sources/src/src/main src/main
RUN mvn -B -q compile assembly:single \
    && cp target/*-jar-with-dependencies.jar /KafkaHandler.jar
COPY sources/go/compat/StreamsCompat.java /tmp/StreamsCompat.java
RUN mkdir /compat && javac -cp /KafkaHandler.jar -d /compat /tmp/StreamsCompat.java

FROM eclipse-temurin:21-jre-noble@sha256:ca397720325ceefe39ce397f186759fc87d9efafb2dc4ce53315980844c2f4f2

RUN apt-get update \
    && apt-get install -y --no-install-recommends software-properties-common \
    && add-apt-repository -y ppa:ondrej/php \
    && apt-get update \
    && apt-get install -y --no-install-recommends php8.5-cli php8.5-mbstring \
    && php -r 'if (PHP_VERSION_ID < 80512 || PHP_VERSION_ID >= 80600) exit(1);' \
    && rm -rf /var/lib/apt/lists/*

RUN groupadd -r worker && useradd -r -g worker worker
WORKDIR /usr/src/myapp
COPY --from=compat-build /KafkaHandler.jar ./
COPY --from=compat-build /compat ./compat
COPY --from=go-build /streams-worker ./
COPY docker/worker/check.sh ./
USER worker
RUN --mount=type=bind,from=go-test,source=/worker.test,target=/tmp/worker.test \
    STREAMS_COMPAT_CLASSPATH=/usr/src/myapp/compat:/usr/src/myapp/KafkaHandler.jar \
    STREAMS_TEST_MBSTRING=1 /tmp/worker.test -test.v -test.run '^(TestReferenceTransforms|TestPHPUnicodeParity)$'
ENTRYPOINT ["/usr/src/myapp/streams-worker"]
