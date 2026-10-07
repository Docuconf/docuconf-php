#!/usr/bin/env bash
# Smoke test: checks the configuration and serves the app with valid env,
# then checks that a bad env is refused. Needs curl and `composer install`.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
php="${PHP:-php}"
tmp="$(mktemp -d)"
pid=""
trap '[ -n "$pid" ] && kill "$pid" 2>/dev/null; rm -rf "$tmp"' EXIT
cd "$here"
rm -rf var/cache

secret='postgres://orders:s3cr3t-pw@localhost:5432/orders'
port="$("$php" -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];')"
export PORT=$port DATABASE_URL="$secret"

# 1. Valid env: the check passes and the app serves /healthz and /config, without the secret.
"$php" bin/console docuconf:check
"$php" -S "127.0.0.1:$port" -t public >"$tmp/out.txt" 2>&1 &
pid=$!
for _ in $(seq 100); do
  curl -fsS "http://127.0.0.1:$port/healthz" >"$tmp/healthz" 2>/dev/null && break
  sleep 0.1
done
[ "$(cat "$tmp/healthz" 2>/dev/null)" = ok ] || { echo "GET /healthz did not return ok" >&2; cat "$tmp/out.txt" >&2; exit 1; }
curl -fsS "http://127.0.0.1:$port/config" >"$tmp/config.json"
if grep -q 's3cr3t-pw' "$tmp/config.json"; then echo "GET /config leaked the secret" >&2; exit 1; fi
grep -q '"DATABASE_URL":"\*\*\*"' "$tmp/config.json" || { echo "GET /config did not redact DATABASE_URL" >&2; exit 1; }
echo "valid env: /healthz ok, /config $(cat "$tmp/config.json")"
kill "$pid"; wait "$pid" 2>/dev/null || true; pid=""

# 2. PORT=0 and no DATABASE_URL: the check exits non-zero and names both.
if PORT=0 DATABASE_URL= "$php" bin/console docuconf:check >"$tmp/bad.txt" 2>&1; then
  echo "docuconf:check passed with PORT=0 and no DATABASE_URL" >&2; exit 1
fi
for code in missing_required out_of_range; do
  grep -q "$code" "$tmp/bad.txt" || { echo "output lacks $code:" >&2; cat "$tmp/bad.txt" >&2; exit 1; }
done
echo "bad env: exited non-zero with:"
sed 's/^/  /' "$tmp/bad.txt"
echo "smoke: ok"
