FROM golang:1.27.3-bookworm@sha256:d9b6a1beb9474adc2a1ce842573639b44b2a0dd49b6669f51d3821baf9ec37c9 AS go-build
WORKDIR /build
COPY sources/go/go.mod sources/go/go.sum ./
RUN go mod download
COPY sources/go/ ./
RUN curl -fsSL --retry 3 https://cdn.jsdelivr.net/npm/jslt-js@0.1.8/dist/jslt-bundle.js -o /build/jslt-bundle.js \
    && printf '%s  %s\n' ce1c69ad5dd4f7e82a69b7fa0b13926266606e3f3a4abd666f407c1e64dbb365 /build/jslt-bundle.js | sha256sum -c - \
    && CGO_ENABLED=0 go build -trimpath -o /streams-worker .

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

FROM eclipse-temurin:21-jre-noble@sha256:ca397720325ceefe39ce397f186759fc87d9efafb2dc4ce53315980844c2f4f2 AS compat-test

# PHP is only needed in the build-time parity test; it is not in the worker image.
RUN apt-get update \
    && apt-get install -y --no-install-recommends software-properties-common \
    && add-apt-repository -y ppa:ondrej/php \
    && apt-get update \
    && apt-get install -y --no-install-recommends php8.5-cli php8.5-mbstring \
    && php -r 'if (PHP_VERSION_ID < 80512 || PHP_VERSION_ID >= 80600) exit(1);' \
    && rm -rf /var/lib/apt/lists/*
COPY --from=compat-build /KafkaHandler.jar /KafkaHandler.jar
COPY --from=compat-build /compat /compat
COPY --from=go-build /build/jslt-bundle.js /usr/src/myapp/jslt-bundle.js
ENV STREAMS_JSLT_BUNDLE=/usr/src/myapp/jslt-bundle.js
RUN --mount=type=bind,from=go-test,source=/worker.test,target=/tmp/worker.test \
    STREAMS_COMPAT_CLASSPATH=/compat:/KafkaHandler.jar \
    STREAMS_TEST_MBSTRING=1 /tmp/worker.test -test.v -test.run '^(TestReferenceTransforms|TestPHPUnicodeParity)$'

# CI-only JVM-free candidate. Keep the hybrid image below as the
# deployment default until unsupported mapping rules are ported.
FROM alpine:3.24.4@sha256:f59abe656b692d901e184be3445e084786602108599d2a51d229c0f5a48c71bf AS native-runtime
ENV STREAMS_NATIVE_ONLY=1
RUN apk add --no-cache ca-certificates \
    && addgroup -S worker \
    && adduser -S -G worker worker
WORKDIR /usr/src/myapp
COPY --from=go-build /streams-worker ./streams-worker
COPY --from=go-build /build/jslt-bundle.js ./jslt-bundle.js
COPY docker/worker/check.sh ./
COPY sources/go/LICENSE.jslt-js ./LICENSE.jslt-js
USER worker
ENTRYPOINT ["/usr/src/myapp/streams-worker"]

# Execute a timezone/JSLT smoke test in the actual Alpine filesystem,
# which deliberately has neither a JRE nor a system tzdata package.
FROM native-runtime AS native-test
COPY --from=go-test /worker.test /worker.test
ENV STREAMS_JSLT_BUNDLE=/usr/src/myapp/jslt-bundle.js
RUN ["/worker.test", "-test.v", "-test.run", "^(TestJSLTNamedTimeZones|TestJSLTReplaceRegexCacheParity|TestNativeMappingAndOutput|TestNativeExponentDynamicSelectors|TestNativeJavaNumberSerialization|TestNativeObjectWildcardValues|TestNativeScalarWildcardInputs|TestStrictNativeMapper|TestStrictNativeMalformedDocuments|TestStrictNativeNonObjectRoots|TestStrictNativeNestedDuplicateKeys|TestURLHashGolden|TestNativeLowercase)$"]

FROM eclipse-temurin:21-jre-noble@sha256:ca397720325ceefe39ce397f186759fc87d9efafb2dc4ce53315980844c2f4f2
RUN groupadd -r worker && useradd -r -g worker worker
WORKDIR /usr/src/myapp
COPY --from=compat-test /KafkaHandler.jar ./
COPY --from=compat-test /compat ./compat
COPY --from=go-build /streams-worker ./
COPY --from=go-build /build/jslt-bundle.js ./jslt-bundle.js
COPY docker/worker/check.sh ./
COPY sources/go/LICENSE.jslt-js ./LICENSE.jslt-js
USER worker
ENTRYPOINT ["/usr/src/myapp/streams-worker"]
