<?php

namespace App;

/** Checks the signature on incoming payment webhooks against the key set in WEBHOOK_KEYS. */
final class Webhooks
{
    /** The largest body POST /webhooks/payments reads. */
    public const MAX_BODY = 1 << 20;

    /**
     * Whether $signature, the hex-encoded HMAC-SHA256 of $body, was made with
     * any of $keys. Accepting every key in the set is what lets a key be
     * rotated: during the overlap the old and the new key both work.
     *
     * @param list<string>|null $keys
     */
    public static function verify(?array $keys, string $body, ?string $signature): bool
    {
        if ($signature === null || $signature === '' || !ctype_xdigit($signature)) {
            return false;
        }
        $ok = false;
        foreach ($keys ?? [] as $key) {
            // Check every key, so the time taken does not say which one matched.
            $ok = hash_equals(hash_hmac('sha256', $body, $key), strtolower($signature)) || $ok;
        }
        return $ok;
    }
}
