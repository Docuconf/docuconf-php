<?php

declare(strict_types=1);

namespace Docuconf\Spec;

use Docuconf\Duration;

/**
 * One file input of a contract (SPEC §4.6).
 */
final class FileSpec
{
    public const TYPES = ['config', 'tls', 'caBundle', 'keystore', 'text', 'binary'];

    public string $description = '';
    public bool $required = false;
    public bool $secret = false;
    public string $path = '';
    public ?string $pathEnv = null;
    public string $reload = 'restart';
    public ?int $maxSize = null;
    public ?string $group = null;
    /** @var array{message: string, replacedBy?: string}|null */
    public ?array $deprecated = null;

    // config (json|yaml|toml) and keystore (pkcs12|jks)
    public ?string $format = null;
    /** @var array<string, mixed>|null */
    public ?array $schema = null;
    /** @var class-string|null the app's type a config file binds to */
    public ?string $class = null;

    // tls
    /** @var list<string>|null */
    public ?array $dnsNames = null;
    /** @var list<string>|null */
    public ?array $keyAlgorithms = null;
    public ?Duration $minRemaining = null;
    public bool $requireCA = false;

    // caBundle
    public int $minCertificates = 1;

    // keystore
    public ?string $passwordVar = null;

    // text
    public ?string $pattern = null;
    public ?int $minLength = null;
    public ?int $maxLength = null;

    public function __construct(public readonly string $name, public readonly string $type)
    {
        if ($type === 'tls' || $type === 'keystore') {
            $this->secret = true;
        }
    }
}
