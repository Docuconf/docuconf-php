#!/usr/bin/env bash
# Smoke test: checks the configuration and serves the app with valid env,
# then checks that a bad env is refused, and posts webhooks signed with each
# key of a key set that is mid-rotation. Needs curl and `composer install`.
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
# Two webhook keys: the old one and, mid-rotation, the new one.
old_key='old-webhook-key-0123456789abcdef0123'
new_key='new-webhook-key-0123456789abcdef0123'
export PORT=$port DATABASE_URL="$secret" WEBHOOK_KEYS="$old_key,$new_key"

# 1. Valid env: the check passes and the app serves /healthz and /config, without the secrets.
"$php" bin/console docuconf:check
"$php" -S "127.0.0.1:$port" -t public >"$tmp/out.txt" 2>&1 &
pid=$!
for _ in $(seq 100); do
  curl -fsS "http://127.0.0.1:$port/healthz" >"$tmp/healthz" 2>/dev/null && break
  sleep 0.1
done
[ "$(cat "$tmp/healthz" 2>/dev/null)" = ok ] || { echo "GET /healthz did not return ok" >&2; cat "$tmp/out.txt" >&2; exit 1; }
curl -fsS "http://127.0.0.1:$port/config" >"$tmp/config.json"
if grep -q -e 's3cr3t-pw' -e 'webhook-key' "$tmp/config.json" "$tmp/out.txt"; then echo "a secret leaked into /config or the log" >&2; exit 1; fi
grep -q '"DATABASE_URL":"\*\*\*"' "$tmp/config.json" || { echo "GET /config did not redact DATABASE_URL" >&2; exit 1; }
grep -q '"WEBHOOK_KEYS":"\*\*\*"' "$tmp/config.json" || { echo "GET /config did not redact WEBHOOK_KEYS" >&2; exit 1; }
echo "valid env: /healthz ok, /config $(cat "$tmp/config.json")"

# Mid-rotation, an unsigned webhook is turned away, one signed with either key
# is accepted, and one signed with any other key is not.
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'X-Signature: 00' -d '{}' "http://127.0.0.1:$port/webhooks/payments")
[ "$code" = 401 ] || { echo "an unsigned webhook got $code, want 401" >&2; exit 1; }
body='{"order":"42","status":"paid"}'
for key in "$old_key" "$new_key" "other-webhook-key-0123456789abcdef"; do
  sig=$("$php" -r 'echo hash_hmac("sha256", $argv[2], $argv[1]);' "$key" "$body")
  code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "X-Signature: $sig" -H 'Content-Type: application/json' -d "$body" "http://127.0.0.1:$port/webhooks/payments")
  want=204; [ "${key#other}" != "$key" ] && want=401
  [ "$code" = "$want" ] || { echo "webhook signed with the ${key%%-*} key: got $code, want $want" >&2; cat "$tmp/out.txt" >&2; exit 1; }
done
echo "webhooks: old and new key accepted, any other rejected"
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

# 3. A key set with an empty second key (a trailing comma) is refused,
# without printing the key.
if WEBHOOK_KEYS="$old_key," "$php" bin/console docuconf:check >"$tmp/bad.txt" 2>&1; then
  echo "docuconf:check passed with an empty webhook key" >&2; exit 1
fi
expected='docuconf: 1 configuration problem:
  - WEBHOOK_KEYS [out_of_range]: has an empty key (key 2 of 2): a stray separator, or an unset item'
if [ "$(cat "$tmp/bad.txt")" != "$expected" ] || grep -q webhook-key "$tmp/bad.txt"; then
  echo "unexpected output for an empty webhook key:" >&2; diff <(echo "$expected") "$tmp/bad.txt" >&2; exit 1
fi
echo "empty webhook key: refused"
echo "smoke: ok"
