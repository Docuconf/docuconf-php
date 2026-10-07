<?php

declare(strict_types=1);

namespace Docuconf\Files;

/**
 * Opens a PKCS#12 keystore with PHP's OpenSSL extension.
 *
 * @internal
 */
final class Pkcs12
{
    /** Returns why the keystore cannot be opened, or null when it opens. */
    public static function verify(string $data, string $password): ?string
    {
        $certs = [];
        if (@openssl_pkcs12_read($data, $certs, $password)) {
            return null;
        }
        $detail = '';
        while (($e = openssl_error_string()) !== false) {
            if (str_contains($e, 'unsupported')) {
                $detail = ' (it may use a legacy cipher such as RC2 that OpenSSL 3 disables by default)';
            }
        }
        return 'cannot be opened as PKCS#12 with its password' . $detail;
    }
}
