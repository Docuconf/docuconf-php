<?php

declare(strict_types=1);

namespace Docuconf\Files;

use Docuconf\Re2;
use Docuconf\Schema\HydrationError;
use Docuconf\Schema\Hydrator;
use Docuconf\Schema\Json;
use Docuconf\Schema\JsonSchema;
use Docuconf\Spec\FileSpec;
use Docuconf\Violation;

/**
 * Checks file inputs at boot (SPEC §11.2 item 7): the path exists and is
 * readable within maxSize, config files parse and bind to the app's type,
 * TLS key pairs, CA bundles and keystores open, text matches its rules.
 *
 * @internal
 */
final class FileChecker
{
    /**
     * @param array<string, string> $env
     * @return array{?LoadedFile, list<Violation>}
     */
    public static function check(FileSpec $f, array $env, ?int $now = null): array
    {
        $path = self::resolvePath($f, $env);
        $name = $f->name;
        $fail = fn (string $code, string $msg) => [null, [new Violation($name, $code, $msg)]];

        if ($f->type === 'tls') {
            if (!is_dir($path)) {
                if (!file_exists($path) && !$f->required) {
                    return [null, []];
                }
                return $fail(file_exists($path) ? 'file_malformed' : 'file_missing', file_exists($path) ? "$path is not a directory" : "$path does not exist");
            }
            $files = ['tls.crt' => null, 'tls.key' => null];
            if ($f->requireCA) {
                $files['ca.crt'] = null;
            }
            $violations = [];
            foreach (array_keys($files) as $file) {
                [$data, $v] = self::read($f, "$path/$file");
                if ($v !== null) {
                    $violations[] = $v;
                }
                $files[$file] = $data;
            }
            if ($violations !== []) {
                return [null, $violations];
            }
            /** @var array{'tls.crt': string, 'tls.key': string, 'ca.crt'?: string} $files */
            $violations = Tls::check($f, $files, "$path/ca.crt", $now ?? time());
            if ($violations !== []) {
                return [null, $violations];
            }
            return [new LoadedFile($name, $path, null, null), []];
        }

        if (!file_exists($path)) {
            if (!$f->required) {
                return [null, []];
            }
            return $fail('file_missing', "$path does not exist");
        }
        [$data, $v] = self::read($f, $path);
        if ($v !== null) {
            return [null, [$v]];
        }
        switch ($f->type) {
            case 'config':
                return self::config($f, $path, $data);
            case 'caBundle':
                $certs = Tls::parseBundle($data);
                if (count($certs) < $f->minCertificates) {
                    return $fail('file_malformed', 'holds ' . count($certs) . " parseable PEM certificate(s), needs at least {$f->minCertificates}");
                }
                return [new LoadedFile($name, $path, null, null), []];
            case 'keystore':
                $password = $f->passwordVar === null ? '' : ($env[$f->passwordVar] ?? '');
                $err = $f->format === 'jks' ? Jks::verify($data, $password) : Pkcs12::verify($data, $password);
                if ($err !== null) {
                    return $fail('keystore_unreadable', $err);
                }
                return [new LoadedFile($name, $path, null, null), []];
            case 'text':
                if (!mb_check_encoding($data, 'UTF-8')) {
                    return $fail('file_malformed', 'is not valid UTF-8 text');
                }
                $len = mb_strlen($data, 'UTF-8');
                if ($f->minLength !== null && $len < $f->minLength) {
                    return $fail('out_of_range', "is shorter than minLength {$f->minLength} ($len characters)");
                }
                if ($f->maxLength !== null && $len > $f->maxLength) {
                    return $fail('out_of_range', "is longer than maxLength {$f->maxLength} ($len characters)");
                }
                if ($f->pattern !== null && !Re2::matches($f->pattern, $data)) {
                    return $fail('pattern_mismatch', 'does not match pattern ' . $f->pattern);
                }
                return [new LoadedFile($name, $path, $data, $data), []];
            default:
                return [new LoadedFile($name, $path, null, null), []];
        }
    }

    /**
     * The path the app reads: `pathEnv` when the platform set it, else the
     * declared path, under DOCUCONF_FILE_ROOT when that is set (SPEC §11.1).
     *
     * @param array<string, string> $env
     */
    public static function resolvePath(FileSpec $f, array $env): string
    {
        $path = $f->path;
        if ($f->pathEnv !== null && ($env[$f->pathEnv] ?? '') !== '') {
            $path = $env[$f->pathEnv];
        }
        $root = $env['DOCUCONF_FILE_ROOT'] ?? '';
        if ($root !== '' && str_starts_with($path, '/')) {
            $path = rtrim($root, '/') . $path;
        }
        return $path;
    }

    /** @return array{string, ?Violation} */
    private static function read(FileSpec $f, string $path): array
    {
        if (!file_exists($path)) {
            return ['', new Violation($f->name, 'file_missing', "$path does not exist")];
        }
        if (is_dir($path) || !is_readable($path)) {
            return ['', new Violation($f->name, 'file_unreadable', "$path is not a readable file")];
        }
        $size = filesize($path);
        if ($f->maxSize !== null && $size !== false && $size > $f->maxSize) {
            return ['', new Violation($f->name, 'file_too_large', "$path is $size bytes, more than maxSize {$f->maxSize}")];
        }
        $data = @file_get_contents($path);
        if ($data === false) {
            return ['', new Violation($f->name, 'file_unreadable', "$path could not be read")];
        }
        return [$data, null];
    }

    /** @return array{?LoadedFile, list<Violation>} */
    private static function config(FileSpec $f, string $path, string $data): array
    {
        $fail = fn (string $code, string $msg) => [null, [new Violation($f->name, $code, $msg)]];
        try {
            $decoded = ConfigFormat::decode($f->format ?? 'json', $data);
        } catch (\Throwable $e) {
            $detail = $f->secret ? '' : ': ' . strtok($e->getMessage(), "\n");
            return $fail('file_malformed', 'is not valid ' . strtoupper($f->format ?? 'json') . $detail);
        }
        if ($f->schema !== null) {
            $json = Json::encode($decoded);
            $err = JsonSchema::validate($f->schema, $decoded, $json);
            if ($err !== null) {
                return $fail('schema_mismatch', 'does not match its schema: ' . $err);
            }
        }
        $value = $decoded;
        if ($f->class !== null) {
            try {
                $value = Hydrator::hydrate($f->class, $decoded);
            } catch (HydrationError $e) {
                return $fail('schema_mismatch', 'does not bind to ' . $f->class . ': ' . $e->getMessage());
            }
        }
        return [new LoadedFile($f->name, $path, $data, $value), []];
    }
}
