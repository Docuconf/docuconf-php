<?php

namespace App;

use Docuconf\KeySet;

/** Checks the signature on incoming payment webhooks against the key set in WEBHOOK_KEYS. */
final class Webhooks
{
    /** The largest body POST /webhooks/payments reads. */
    public const MAX_BODY = 1 << 20;

    /**
     * Whether $signature, the hex-encoded HMAC-SHA256 of $body, was made with
     * any key in $keys. Accepting every key in the set is what lets a key be
     * rotated: during the overlap the old and the new key both work.
     * KeySet::verify() tries every key, so the time taken does not say which
     * one matched.
     */
    public static function verify(?KeySet $keys, string $body, ?string $signature): bool
    {
        if ($keys === null || $signature === null || $signature === '' || !ctype_xdigit($signature)) {
            return false;
        }
        $signature = strtolower($signature);
        return $keys->verify(fn (string $key) => hash_equals(hash_hmac('sha256', $body, $key), $signature));
    }
}
