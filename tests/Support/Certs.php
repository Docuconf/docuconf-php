<?php

declare(strict_types=1);

namespace Docuconf\Tests\Support;

/**
 * Generates real certificates and keys for the file tests, with PHP's
 * OpenSSL extension.
 */
final class Certs
{
    /**
     * A key pair signed by $ca (or self-signed), covering $dnsNames.
     *
     * @param list<string> $dnsNames
     * @param array{string, string}|null $ca [certPem, keyPem] of the issuing CA
     * @return array{string, string} [certPem, keyPem]
     */
    public static function issue(array $dnsNames, int $days = 365, string $type = 'RSA', ?array $ca = null, bool $isCa = false): array
    {
        $key = self::key($type);
        $config = self::config($dnsNames);
        try {
            $options = ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => $isCa ? 'v3_ca' : 'v3_leaf', 'req_extensions' => 'v3_leaf'];
            $csr = openssl_csr_new(['commonName' => $dnsNames[0] ?? 'docuconf test'], $key, $options);
            if ($csr === false || $csr === true) {
                throw new \RuntimeException('openssl_csr_new failed: ' . openssl_error_string());
            }
            $caCert = $ca === null ? null : $ca[0];
            $caKey = $ca === null ? $key : openssl_pkey_get_private($ca[1]);
            if ($caKey === false) {
                throw new \RuntimeException('bad CA key');
            }
            $cert = openssl_csr_sign($csr, $caCert, $caKey, $days, $options, random_int(1, PHP_INT_MAX));
            if ($cert === false) {
                throw new \RuntimeException('openssl_csr_sign failed: ' . openssl_error_string());
            }
            openssl_x509_export($cert, $certPem);
            openssl_pkey_export($key, $keyPem, null, ['config' => $config]);
            return [$certPem, $keyPem];
        } finally {
            @unlink($config);
        }
    }

    /** @return array{string, string} a CA's [certPem, keyPem] */
    public static function ca(string $name = 'docuconf test CA'): array
    {
        return self::issue([$name], 3650, 'RSA', null, true);
    }

    /** Writes a kubernetes.io/tls directory: tls.crt, tls.key and optionally ca.crt. */
    public static function writeTlsDir(string $dir, string $cert, string $key, ?string $caCert = null): void
    {
        @mkdir($dir, 0o777, true);
        file_put_contents("$dir/tls.crt", $cert);
        file_put_contents("$dir/tls.key", $key);
        if ($caCert !== null) {
            file_put_contents("$dir/ca.crt", $caCert);
        }
    }

    /** A PKCS#12 keystore holding a fresh key pair. */
    public static function pkcs12(string $password): string
    {
        [$cert, $key] = self::issue(['client.example.com']);
        openssl_pkcs12_export($cert, $p12, $key, $password);
        return $p12;
    }

    /**
     * A JKS file with no entries and a valid integrity digest. Enough for
     * the integrity check, which is all the SDK does with JKS.
     */
    public static function jks(string $password): string
    {
        $body = "\xFE\xED\xFE\xED" . pack('N', 2) . pack('N', 0);
        $pw = mb_convert_encoding($password, 'UTF-16BE', 'UTF-8');
        return $body . sha1($pw . 'Mighty Aphrodite' . $body, true);
    }

    private static function key(string $type): \OpenSSLAsymmetricKey
    {
        $options = match ($type) {
            'RSA' => ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048],
            'ECDSA' => ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'],
            default => throw new \InvalidArgumentException("unsupported key type $type"),
        };
        $key = openssl_pkey_new($options);
        if ($key === false) {
            throw new \RuntimeException('openssl_pkey_new failed: ' . openssl_error_string());
        }
        return $key;
    }

    /** @param list<string> $dnsNames */
    private static function config(array $dnsNames): string
    {
        $san = implode(',', array_map(fn ($n) => "DNS:$n", $dnsNames));
        $path = (string) tempnam(sys_get_temp_dir(), 'docuconf-openssl');
        file_put_contents($path, <<<CNF
            [req]
            distinguished_name = dn
            [dn]
            [v3_leaf]
            basicConstraints = CA:FALSE
            subjectAltName = $san
            [v3_ca]
            basicConstraints = critical,CA:TRUE
            keyUsage = critical,keyCertSign,cRLSign
            subjectKeyIdentifier = hash
            CNF);
        return $path;
    }
}
