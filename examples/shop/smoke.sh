#!/usr/bin/env bash
# Smoke test: runs the shop service (bin/shop) as each role with a valid
# environment, and with JSON logs but no LOG_SCHEMA_URL, and checks what it
# prints and its exit code. Needs `composer install` run in this directory.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
php="${PHP:-php}"
root="$(mktemp -d)" # an empty file root: no webhook signing key
trap 'rm -rf "$root"' EXIT
cd "$here"

# Runs bin/shop with only the base environment and the given variables, and
# checks its exit code, stdout and stderr.
expect() {
  local want_code="$1" want_out="$2" want_err="$3"; shift 3
  local code=0
  env -i PATH="$PATH" DATABASE_URL='postgres://shop@db:5432/shop' DOCUCONF_FILE_ROOT="$root" "$@" \
    "$php" bin/shop >"$root.out" 2>"$root.err" || code=$?
  if [ "$code" != "$want_code" ] || [ "$(cat "$root.out")" != "$want_out" ] || [ "$(cat "$root.err")" != "$want_err" ]; then
    echo "bin/shop with $*: exit $code (want $want_code)" >&2
    echo "stdout:" >&2; cat "$root.out" >&2
    echo "stderr:" >&2; cat "$root.err" >&2
    rm -f "$root.out" "$root.err"
    exit 1
  fi
  rm -f "$root.out" "$root.err"
  echo "$*: exit $code, ${want_out:-$want_err}"
}

# 1. The web role.
expect 0 'shop web: port 8080, origins https://shop.example' '' \
  DOCUCONF_ROLE=web ALLOWED_ORIGINS=https://shop.example

# 2. The worker role, which needs no ALLOWED_ORIGINS.
expect 0 'shop worker: queues default,mail, webhooks off' '' \
  DOCUCONF_ROLE=worker QUEUES=default,mail

# 6. JSON logs require LOG_SCHEMA_URL.
expect 1 '' 'docuconf: 1 configuration problem:
  - LOG_SCHEMA_URL [missing_required]: is required when LOG_FORMAT is "json"' \
  DOCUCONF_ROLE=worker QUEUES=default LOG_FORMAT=json

echo "smoke: ok"
