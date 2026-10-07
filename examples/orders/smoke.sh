#!/usr/bin/env bash
# Smoke test: starts the orders service with valid env and checks its
# endpoints, then starts it with bad env and checks it refuses to boot.
# Needs curl, setsid, and `composer install` run in this directory.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
php="${PHP:-php}"
tmp="$(mktemp -d)"
pid=""
stop() { [ -n "$pid" ] && kill -TERM -- "-$pid" 2>/dev/null; pid=""; }
trap 'stop; rm -rf "$tmp"' EXIT
cd "$here"

secret='postgres://orders:s3cr3t-pw@localhost:5432/orders'
# A free port, so the test never talks to another server.
port="$("$php" -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];')"

# 1. Valid env: the service serves /healthz and /config, without the secret.
PORT=$port DATABASE_URL="$secret" setsid "$php" artisan serve --host=127.0.0.1 --port="$port" >"$tmp/out.txt" 2>&1 &
pid=$!
for _ in $(seq 100); do
  kill -0 "$pid" 2>/dev/null || { echo "the service exited:" >&2; cat "$tmp/out.txt" >&2; exit 1; }
  curl -fsS "http://127.0.0.1:$port/healthz" >"$tmp/healthz" 2>/dev/null && break
  sleep 0.1
done
[ "$(cat "$tmp/healthz" 2>/dev/null)" = ok ] || { echo "GET /healthz did not return ok" >&2; cat "$tmp/out.txt" >&2; exit 1; }
curl -fsS "http://127.0.0.1:$port/config" >"$tmp/config.json"
if grep -q 's3cr3t-pw' "$tmp/config.json"; then
  echo "GET /config leaked the secret" >&2; exit 1
fi
grep -q '"DATABASE_URL":"\*\*\*"' "$tmp/config.json" || { echo "GET /config did not redact DATABASE_URL" >&2; exit 1; }
echo "valid env: /healthz ok, /config $(cat "$tmp/config.json")"
stop

# 2. PORT=0 and no DATABASE_URL: the service exits non-zero and names both.
# (An empty DATABASE_URL counts as unset, and keeps a local .env from setting it.)
if PORT=0 DATABASE_URL= "$php" artisan serve --port="$port" >"$tmp/bad.txt" 2>&1; then
  echo "service started with PORT=0 and no DATABASE_URL" >&2; exit 1
fi
for code in missing_required out_of_range; do
  grep -q "$code" "$tmp/bad.txt" || { echo "startup output lacks $code:" >&2; cat "$tmp/bad.txt" >&2; exit 1; }
done
echo "bad env: exited non-zero with:"
sed 's/^/  /' "$tmp/bad.txt"

# 3. Any command that runs the app validates too, not only serve.
if PORT=0 DATABASE_URL="$secret" "$php" artisan migrate:status >"$tmp/cmd.txt" 2>&1; then
  echo "migrate:status ran with PORT=0" >&2; exit 1
fi
grep -q 'PORT \[out_of_range\]' "$tmp/cmd.txt" || { echo "migrate:status output lacks the report:" >&2; cat "$tmp/cmd.txt" >&2; exit 1; }
echo "migrate:status with PORT=0: refused"

# 4. A config cache built before the real environment existed (as in an image
# build) is caught: the app would read the cached values, not the env.
trap 'stop; "$php" artisan config:clear >/dev/null 2>&1 || true; rm -rf "$tmp"' EXIT
DATABASE_URL= PORT= "$php" artisan config:cache >/dev/null
if PORT=$port DATABASE_URL="$secret" "$php" artisan docuconf:check >"$tmp/stale.txt" 2>&1; then
  echo "docuconf:check passed with a stale config cache" >&2; exit 1
fi
grep -q 'config cache is stale' "$tmp/stale.txt" || { echo "docuconf:check output lacks the stale-cache report:" >&2; cat "$tmp/stale.txt" >&2; exit 1; }
PORT=$port DATABASE_URL="$secret" "$php" artisan config:cache >/dev/null
PORT=$port DATABASE_URL="$secret" "$php" artisan docuconf:check
"$php" artisan config:clear >/dev/null
echo "config:cache: a stale cache is refused, a fresh one passes"
echo "smoke: ok"
