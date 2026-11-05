#!/bin/sh
set -eu
clock_nonce=${CONTROL_E2E_ISOLATION_NONCE:-}
# Refuse before loading the library. No host clock/capability or HTTP bypass.
if [ "${APP_ENV:-}" != test ] || [ "${CONTROL_TEST_ISOLATED:-}" != 1 ] \
  || [ "${PLATFORM_TEST_CLOCK:-}" != 1 ] || [ "${#clock_nonce}" -lt 32 ]; then
  echo 'Clock image requires an explicitly isolated test runtime.' >&2; exit 126
fi
if [ ! -r /run/platform-test-clock/time ]; then
  echo 'Shared test clock file is required.' >&2; exit 126
fi
case "${PLATFORM_TEST_CLOCK_KIND:-}" in
  postgres)
    [ "${POSTGRES_DB:-}" = platform_commercial_test ] || exit 126
    original=/usr/local/bin/docker-entrypoint.sh ;;
  php)
    case "${CONTROL_DATABASE_URL:-}" in
      *@control-postgres-test:5432/platform_commercial_test\?*) ;;
      *) echo 'Clock PHP requires the isolated test database.' >&2; exit 126 ;;
    esac
    original=/usr/local/bin/docker-php-entrypoint ;;
  *) exit 126 ;;
esac
export LD_PRELOAD=/opt/platform-test/libfaketime.so.1
unset FAKETIME
export FAKETIME_TIMESTAMP_FILE=/run/platform-test-clock/time
export FAKETIME_NO_CACHE=1 FAKETIME_DONT_FAKE_MONOTONIC=1 FAKETIME_DONT_FAKE_STAT=1
export FAKETIME_DISABLE_SHM=1
exec "$original" "$@"
