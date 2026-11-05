# Disposable browser Billing tests only. Never used by production Compose.
ARG CLOCK_BASE_IMAGE=platform-postgres:local
FROM ${CLOCK_BASE_IMAGE} AS clock-runtime
USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends libfaketime=0.9.10+2024-06-05+gba9ed5b2-0.6 \
    && mkdir -p /opt/platform-test \
    && cp "$(dpkg -L libfaketime | sed -n '/\/libfaketimeMT.so.1$/p')" /opt/platform-test/libfaketime.so.1 \
    && rm -rf /var/lib/apt/lists/*
COPY --chmod=0755 infra/testing/clock-entrypoint.sh /opt/platform-test/entrypoint
ARG CLOCK_KIND=postgres
ARG CLOCK_RUNTIME_USER=root
ENV PLATFORM_TEST_CLOCK_KIND=${CLOCK_KIND}
LABEL org.platform.testing.clock="1"
USER ${CLOCK_RUNTIME_USER}
ENTRYPOINT ["/opt/platform-test/entrypoint"]
