<?php

declare(strict_types=1);

namespace Docuconf\Files;

/**
 * Java KeyStore (JKS and JCEKS) integrity check.
 *
 * PHP has no JKS parser, so, as SPEC §11.2 item 7 allows, the SDK verifies
 * the keystore's integrity digest instead: SHA-1 over the password (as
 * UTF-16BE), the string "Mighty Aphrodite" and the keystore body. A wrong
 * password or a corrupted file fails it. Entries are not decrypted.
 *
 * @internal
 */
final class Jks
{
    /** Returns why the keystore cannot be verified, or null when it can. */
    public static function verify(string $data, string $password): ?string
    {
        if (strlen($data) < 32) {
            return 'is too short to be a JKS keystore';
        }
        $magic = substr($data, 0, 4);
        if ($magic !== "\xFE\xED\xFE\xED" && $magic !== "\xCE\xCE\xCE\xCE") {
            if (Pkcs12::verify($data, $password) === null) {
                return null; // Java 9+ writes PKCS#12 by default, even for "jks" stores.
            }
            return 'is not a JKS keystore';
        }
        $body = substr($data, 0, -20);
        $digest = substr($data, -20);
        $pw = mb_convert_encoding($password, 'UTF-16BE', 'UTF-8');
        if (!hash_equals(sha1($pw . 'Mighty Aphrodite' . $body, true), $digest)) {
            return 'fails its integrity check: the password is wrong or the file is corrupted';
        }
        return null;
    }
}
