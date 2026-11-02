# Keep PostgreSQL and its data format unchanged; rebuild the privilege-drop helper
# with a patched Go toolchain instead of shipping the upstream Go 1.24.6 binary.
FROM --platform=$BUILDPLATFORM golang:1.27.3-trixie@sha256:c65ecfe613b539ac74c62a4c3b2c27e8f8a6076384c8f27e8a3c0f3be17cc385 AS gosu-builder

ARG TARGETOS
ARG TARGETARCH
ARG TARGETVARIANT

WORKDIR /src/gosu
RUN curl --fail --location --retry 3 \
        https://codeload.github.com/tianon/gosu/tar.gz/6456aaa0f3c854d199d0f037f068eb97515b7513 \
        --output /tmp/gosu.tar.gz \
    && echo '33d7537d588ea49458b9509bcf4554bdf5ceacc66da71e5caa1058ea3b689c3b  /tmp/gosu.tar.gz' | sha256sum --check - \
    && tar --extract --gzip --file /tmp/gosu.tar.gz --strip-components=1 \
    && go mod download \
    && go mod verify \
    && if [ "$TARGETARCH" = arm ]; then export GOARM="${TARGETVARIANT#v}"; fi \
    && CGO_ENABLED=0 GOOS="$TARGETOS" GOARCH="$TARGETARCH" GOTOOLCHAIN=local \
        go build -mod=readonly -trimpath -buildvcs=false -ldflags '-d -w' -o /out/gosu .

FROM postgres:18.6-trixie@sha256:3a4264520e121176619f54ff867d12774bc81e95e7b84db58d63bccdb6144b81
COPY --from=gosu-builder --chmod=0755 /out/gosu /usr/local/bin/gosu
RUN gosu --version \
    && test "$(gosu nobody id -u)" = "$(id -u nobody)"
