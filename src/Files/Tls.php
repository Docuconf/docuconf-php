<?php

declare(strict_types=1);

namespace Docuconf\Files;

use Docuconf\Spec\FileSpec;
use Docuconf\Violation;

/**
 * TLS key pair checks with PHP's OpenSSL extension (SPEC §11.2 item 7):
 * the certificate and key parse and match, the certificate is valid now
 * with at least `minRemaining` left, covers every name in `dnsNames`,
 * uses an allowed key algorithm, and chains to `ca.crt` when `requireCA`.
 *
 * @internal
 */
final class Tls
{
    /**
     * @param array{'tls.crt': string, 'tls.key': string, 'ca.crt'?: string} $files
     * @return list<Violation>
     */
    public static function check(FileSpec $f, array $files, string $caPath, int $now): array
    {
        $out = [];
        $fail = function (string $code, string $msg) use (&$out, $f): void {
            $out[] = new Violation($f->name, $code, $msg);
        };
        $chain = self::parseBundle($files['tls.crt']);
        if ($chain === []) {
            $fail('certificate_invalid', 'tls.crt is not a PEM certificate');
            return $out;
        }
        $leaf = $chain[0];
        $key = @openssl_pkey_get_private($files['tls.key']);
        if ($key === false) {
            self::clearErrors();
            $fail('certificate_invalid', 'tls.key is not a readable, unencrypted PEM private key');
            return $out;
        }
        if (!@openssl_x509_check_private_key($leaf, $key)) {
            self::clearErrors();
            $fail('key_mismatch', 'tls.key does not match the certificate in tls.crt');
            return $out;
        }
        $info = openssl_x509_parse($leaf);
        if ($info === false) {
            $fail('certificate_invalid', 'tls.crt could not be parsed');
            return $out;
        }
        $from = (int) $info['validFrom_time_t'];
        $to = (int) $info['validTo_time_t'];
        if ($from > $now) {
            $fail('certificate_invalid', 'the certificate is not valid until ' . gmdate('Y-m-d\TH:i:s\Z', $from));
        } elseif ($to <= $now) {
            $fail('certificate_invalid', 'the certificate expired at ' . gmdate('Y-m-d\TH:i:s\Z', $to));
        } elseif ($f->minRemaining !== null && ($to - $now) < $f->minRemaining->toSeconds()) {
            $fail('certificate_expiring', 'the certificate expires at ' . gmdate('Y-m-d\TH:i:s\Z', $to)
                . ", less than minRemaining {$f->minRemaining} from now");
        }

        if ($f->keyAlgorithms !== null && $f->keyAlgorithms !== []) {
            $alg = self::keyAlgorithm($leaf);
            if (!in_array($alg, $f->keyAlgorithms, true)) {
                $fail('certificate_invalid', 'the key algorithm ' . ($alg ?? 'unknown') . ' is not one of ' . implode(', ', $f->keyAlgorithms));
            }
        }

        if ($f->dnsNames !== null) {
            $sans = self::dnsNames($info);
            $missing = array_values(array_filter($f->dnsNames, fn (string $n) => !self::covers($sans, $n)));
            if ($missing !== []) {
                $fail('certificate_name_mismatch', 'the certificate does not cover ' . implode(', ', $missing)
                    . ' (it covers ' . ($sans === [] ? 'no DNS names' : implode(', ', $sans)) . ')');
            }
        }

        if ($f->requireCA) {
            $cas = self::parseBundle($files['ca.crt'] ?? '');
            if ($cas === []) {
                $fail('certificate_invalid', 'ca.crt holds no PEM certificate');
            } elseif (!self::chainsTo($leaf, array_slice($chain, 1), $caPath)) {
                $fail('certificate_invalid', 'the certificate does not chain to a CA in ca.crt');
            }
        }
        return $out;
    }

    /**
     * Every PEM certificate in $pem that OpenSSL can parse.
     *
     * @return list<\OpenSSLCertificate>
     */
    public static function parseBundle(string $pem): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m);
        $out = [];
        foreach ($m[0] as $block) {
            $cert = @openssl_x509_read($block);
            if ($cert !== false) {
                $out[] = $cert;
            }
        }
        self::clearErrors();
        return $out;
    }

    /** RSA, ECDSA, Ed25519, or null for anything else. */
    public static function keyAlgorithm(\OpenSSLCertificate $cert): ?string
    {
        $pub = openssl_pkey_get_public($cert);
        if ($pub === false) {
            self::clearErrors();
            return null;
        }
        $details = openssl_pkey_get_details($pub);
        if ($details === false) {
            return null;
        }
        if ($details['type'] === OPENSSL_KEYTYPE_RSA) {
            return 'RSA';
        }
        if ($details['type'] === OPENSSL_KEYTYPE_EC) {
            return 'ECDSA';
        }
        // PHP has no key type constant for Ed25519 before 8.4: look for its
        // OID (1.3.101.112) in the SubjectPublicKeyInfo.
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', (string) $details['key']) ?? '', true);
        if ($der !== false && str_contains(substr($der, 0, 16), "\x06\x03\x2b\x65\x70")) {
            return 'Ed25519';
        }
        return null;
    }

    /**
     * @param array<string, mixed> $info
     * @return list<string>
     */
    private static function dnsNames(array $info): array
    {
        $san = $info['extensions']['subjectAltName'] ?? '';
        $out = [];
        foreach (explode(',', is_string($san) ? $san : '') as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $out[] = strtolower(substr($entry, 4));
            }
        }
        return $out;
    }

    /** @param list<string> $sans */
    private static function covers(array $sans, string $name): bool
    {
        $name = strtolower($name);
        foreach ($sans as $san) {
            if ($san === $name) {
                return true;
            }
            // A wildcard covers exactly one label: *.example.com covers a.example.com.
            if (str_starts_with($san, '*.')) {
                $dot = strpos($name, '.');
                if ($dot !== false && $dot > 0 && substr($name, $dot) === substr($san, 1)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param list<\OpenSSLCertificate> $intermediates */
    private static function chainsTo(\OpenSSLCertificate $leaf, array $intermediates, string $caPath): bool
    {
        $untrusted = null;
        if ($intermediates !== []) {
            $untrusted = tempnam(sys_get_temp_dir(), 'docuconf');
            if ($untrusted === false) {
                return false;
            }
            $pem = '';
            foreach ($intermediates as $c) {
                openssl_x509_export($c, $out);
                $pem .= $out;
            }
            file_put_contents($untrusted, $pem);
        }
        try {
            $ok = $untrusted === null
                ? @openssl_x509_checkpurpose($leaf, X509_PURPOSE_ANY, [$caPath])
                : @openssl_x509_checkpurpose($leaf, X509_PURPOSE_ANY, [$caPath], $untrusted);
            self::clearErrors();
            return $ok === true;
        } finally {
            if ($untrusted !== null) {
                @unlink($untrusted);
            }
        }
    }

    private static function clearErrors(): void
    {
        while (openssl_error_string() !== false) {
            // drain OpenSSL's error queue so later calls report their own errors
        }
    }
}
