#!/usr/bin/env bash
# Smoke test: starts the orders service with valid env and checks its
# endpoints, then starts it with bad env and checks it refuses to boot, and
# posts webhooks signed with each key of a key set that is mid-rotation.
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
# Two webhook keys: the old one and, mid-rotation, the new one.
old_key='old-webhook-key-0123456789abcdef0123'
new_key='new-webhook-key-0123456789abcdef0123'
# A free port, so the test never talks to another server.
port="$("$php" -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];')"

wait_for_healthz() {
  for _ in $(seq 100); do
    kill -0 "$pid" 2>/dev/null || { echo "the service exited:" >&2; cat "$tmp/out.txt" >&2; exit 1; }
    curl -fsS "http://127.0.0.1:$port/healthz" >"$tmp/healthz" 2>/dev/null && break
    sleep 0.1
  done
  [ "$(cat "$tmp/healthz" 2>/dev/null)" = ok ] || { echo "GET /healthz did not return ok" >&2; cat "$tmp/out.txt" >&2; exit 1; }
}

# 1. Valid env: the service serves /healthz and /config, without the secrets,
# and turns away an unsigned webhook.
PORT=$port DATABASE_URL="$secret" WEBHOOK_KEYS="$old_key,$new_key" setsid "$php" artisan serve --host=127.0.0.1 --port="$port" >"$tmp/out.txt" 2>&1 &
pid=$!
wait_for_healthz
curl -fsS "http://127.0.0.1:$port/config" >"$tmp/config.json"
if grep -q -e 's3cr3t-pw' -e 'webhook-key' "$tmp/config.json" "$tmp/out.txt"; then
  echo "a secret leaked into /config or the log" >&2; exit 1
fi
grep -q '"DATABASE_URL":"\*\*\*"' "$tmp/config.json" || { echo "GET /config did not redact DATABASE_URL" >&2; exit 1; }
grep -q '"WEBHOOK_KEYS":"\*\*\*"' "$tmp/config.json" || { echo "GET /config did not redact WEBHOOK_KEYS" >&2; exit 1; }
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'X-Signature: 00' -d '{}' "http://127.0.0.1:$port/webhooks/payments")
[ "$code" = 401 ] || { echo "an unsigned webhook got $code, want 401" >&2; exit 1; }
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

# 3b. A key set with an empty second key (a trailing comma) fails at boot,
# without printing the key.
if PORT=$port DATABASE_URL="$secret" WEBHOOK_KEYS="$old_key," "$php" artisan serve --port="$port" >"$tmp/bad.txt" 2>&1; then
  echo "service started with an empty webhook key" >&2; exit 1
fi
expected='docuconf: 1 configuration problem:
  - WEBHOOK_KEYS [out_of_range]: has an empty key (key 2 of 2): a stray separator, or an unset item'
if [ "$(cat "$tmp/bad.txt")" != "$expected" ] || grep -q webhook-key "$tmp/bad.txt"; then
  echo "unexpected output for an empty webhook key:" >&2; diff <(echo "$expected") "$tmp/bad.txt" >&2; exit 1
fi
echo "empty webhook key: refused"

# 3c. Mid-rotation, a webhook signed with either key is accepted, and one
# signed with any other key is not.
PORT=$port DATABASE_URL="$secret" WEBHOOK_KEYS="$old_key,$new_key" setsid "$php" artisan serve --host=127.0.0.1 --port="$port" >"$tmp/out.txt" 2>&1 &
pid=$!
wait_for_healthz
body='{"order":"42","status":"paid"}'
for key in "$old_key" "$new_key" "other-webhook-key-0123456789abcdef"; do
  sig=$("$php" -r 'echo hash_hmac("sha256", $argv[2], $argv[1]);' "$key" "$body")
  code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "X-Signature: $sig" -H 'Content-Type: application/json' -d "$body" "http://127.0.0.1:$port/webhooks/payments")
  want=204; [ "${key#other}" != "$key" ] && want=401
  [ "$code" = "$want" ] || { echo "webhook signed with the ${key%%-*} key: got $code, want $want" >&2; exit 1; }
done
echo "webhooks: old and new key accepted, any other rejected"
stop

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
