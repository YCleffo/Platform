# Keep PostgreSQL and its data format unchanged; rebuild the privilege-drop helper
# with a patched Go toolchain instead of shipping the upstream Go 1.24.6 binary.
FROM --platform=$BUILDPLATFORM golang:1.27.3-trixie@sha256:af1bfc2614ebb1dbd31af514b78740fd678850e76498762b425ecee4c5c6ea80 AS gosu-builder

ARG TARGETOS
ARG TARGETARCH
ARG TARGETVARIANT

WORKDIR /src/gosu
RUN --mount=type=cache,target=/go/pkg/mod \
    --mount=type=cache,target=/root/.cache/go-build \
    curl --fail --location --retry 3 \
        https://codeload.github.com/tianon/gosu/tar.gz/6456aaa0f3c854d199d0f037f068eb97515b7513 \
        --output /tmp/gosu.tar.gz \
    && echo '33d7537d588ea49458b9509bcf4554bdf5ceacc66da71e5caa1058ea3b689c3b  /tmp/gosu.tar.gz' | sha256sum --check - \
    && tar --extract --gzip --file /tmp/gosu.tar.gz --strip-components=1 \
    && go mod download \
    && go mod verify \
    && if [ "$TARGETARCH" = arm ]; then export GOARM="${TARGETVARIANT#v}"; fi \
    && CGO_ENABLED=0 GOOS="$TARGETOS" GOARCH="$TARGETARCH" GOTOOLCHAIN=local \
        go build -mod=readonly -trimpath -buildvcs=false -ldflags '-d -w' -o /out/gosu .

FROM postgres:18.6-trixie@sha256:bd5dfa494a5a259704365813dbfa5fa6b3e69be7348d43b547f540c730478583
# Update the package manager and zlib security fixes while keeping PostgreSQL
# and PGDATA compatible with the pinned 18.6 base.
RUN apt-get -o Acquire::Retries=0 update \
    && apt-get -o Acquire::Retries=0 install -y --no-install-recommends --only-upgrade apt libapt-pkg7.0 zlib1g \
    && dpkg --compare-versions "$(dpkg-query -W -f='${Version}' apt)" ge '3.0.4' \
    && dpkg --compare-versions "$(dpkg-query -W -f='${Version}' zlib1g)" ge '1:1.3.dfsg+really1.3.1-1+deb13u1' \
    && rm -rf /var/lib/apt/lists/*
COPY --from=gosu-builder --chmod=0755 /out/gosu /usr/local/bin/gosu
RUN gosu --version \
    && test "$(gosu nobody id -u)" = "$(id -u nobody)"
