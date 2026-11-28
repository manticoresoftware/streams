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

# The deployed image contains only the native worker, the JSLT bundle, and
# its license. The Java reference implementation is used by CI, not shipped.
FROM alpine:3.24.4@sha256:f59abe656b692d901e184be3445e084786602108599d2a51d229c0f5a48c71bf AS native-runtime
RUN apk add --no-cache ca-certificates \
    && addgroup -S worker \
    && adduser -S -G worker worker
WORKDIR /usr/src/myapp
COPY --from=go-build /streams-worker ./streams-worker
COPY --from=go-build /build/jslt-bundle.js ./jslt-bundle.js
COPY docker/worker/check.sh ./
COPY sources/go/LICENSE.jslt-js ./LICENSE.jslt-js
ENV STREAMS_JSLT_BUNDLE=/usr/src/myapp/jslt-bundle.js
USER worker
ENTRYPOINT ["/usr/src/myapp/streams-worker"]

# CI-only: verify native mapping/JSLT inside the actual Alpine filesystem.
FROM native-runtime AS native-test
COPY --from=go-test /worker.test /worker.test
COPY --chown=worker:worker sources/go/testdata/ ./testdata/
RUN /usr/src/myapp/streams-worker --audit-mapping | grep -q '"static_native":true'
RUN ["/worker.test", "-test.v", "-test.run", "^(TestJSLTNamedTimeZones|TestJSLTParseTimePrefixNative|TestJSLTReplaceRegexCacheParity|TestNativeMappingAndOutput|TestNativeExponentDynamicSelectors|TestNativeJavaNumberSerialization|TestNativeObjectWildcardValues|TestNativeScalarWildcardInputs|TestStrictNativeMapper|TestStrictNativeMalformedDocuments|TestStrictNativeNonObjectRoots|TestStrictNativeNestedDuplicateKeys|TestURLHashGolden|TestNativeLowercase|TestLegacyTerminalFunctionParity|TestFrozenJavaMappingGolden|TestAuditWorkerMapping)$"]

# CI-only: broker-backed replay/commit fault tests, never deployed as a worker.
FROM native-runtime AS real-kafka-test
COPY --from=go-test /worker.test /worker.test

# Default image for deployment and staging: identical to native-runtime.
FROM native-runtime
