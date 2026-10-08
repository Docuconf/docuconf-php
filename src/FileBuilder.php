<?php

declare(strict_types=1);

namespace Docuconf;

use Docuconf\Schema\SchemaGenerator;
use Docuconf\Spec\FileSpec;

/**
 * Declares one file input (SPEC §4.6):
 *
 * ```php
 * $env->tls('serving-tls', '/etc/orders/tls')
 *     ->describe('Certificate the service serves HTTPS with')
 *     ->required()
 *     ->dnsNames('orders.internal')
 *     ->minRemaining('720h');
 * ```
 */
final class FileBuilder
{
    /** @var array{string, int}|null */
    private ?array $docSite = null;
    private bool $detailsSet = false;

    /** @internal */
    public function __construct(private readonly FileSpec $spec)
    {
    }

    /** @internal */
    public function spec(): FileSpec
    {
        return $this->spec;
    }

    public function describe(string $description): self
    {
        $this->spec->description = $description;
        return $this;
    }

    /**
     * Longer documentation for generated docs, in CommonMark: why the
     * file input exists and when to change it. At most 4000 characters, never
     * read at runtime. By default, the PHPDoc comment before the
     * declaration gives it (see the README).
     */
    public function details(string $details): self
    {
        $this->spec->details = $details;
        $this->detailsSet = true;
        return $this;
    }

    /**
     * Where the declaration is, for its PHPDoc comment.
     *
     * @internal
     * @param array{string, int}|null $site
     */
    public function documentedAt(?array $site): self
    {
        $this->docSite = $site;
        return $this;
    }

    /** Fills the description and details from the PHPDoc comment, where not given. */
    private function applyDoc(): void
    {
        if ($this->docSite === null) {
            return;
        }
        $comment = Docs::commentAt(...$this->docSite);
        $this->docSite = null;
        if ($comment === null) {
            return;
        }
        [$description, $details] = Docs::split($comment);
        if (trim($this->spec->description) === '' && $description !== '') {
            $this->spec->description = $description;
        }
        if (!$this->detailsSet) {
            $this->spec->details = $details;
        }
    }

    public function required(bool $required = true): self
    {
        $this->spec->required = $required;
        return $this;
    }

    /** The content must come from a secret store. Always true for tls and keystore. */
    public function secret(bool $secret = true): self
    {
        $this->spec->secret = $secret;
        return $this;
    }

    /** An env variable the platform sets to the path, for apps that read it from there. */
    public function pathEnv(string $name): self
    {
        $this->spec->pathEnv = $name;
        return $this;
    }

    /** Upper bound in bytes. */
    public function maxSize(int $bytes): self
    {
        $this->spec->maxSize = $bytes;
        return $this;
    }

    /**
     * "restart" (the default): the app reads the file at boot, so a change
     * needs a rollout. This SDK does not reload files, so "watch" is
     * rejected rather than promised.
     */
    public function reload(string $mode): self
    {
        $this->spec->reload = $mode;
        return $this;
    }

    public function group(string $group): self
    {
        $this->spec->group = $group;
        return $this;
    }

    public function deprecated(string $message, ?string $replacedBy = null): self
    {
        $this->spec->deprecated = $replacedBy === null ? ['message' => $message] : ['message' => $message, 'replacedBy' => $replacedBy];
        return $this;
    }

    /**
     * config files: the app's type the file binds to. The JSON Schema is
     * generated from it, and the loaded value is an instance of it.
     *
     * @param class-string|array<string, mixed> $schema a class, or a JSON Schema array
     */
    public function schema(string|array $schema): self
    {
        if (is_string($schema)) {
            $this->spec->class = $schema;
        } else {
            $this->spec->schema = $schema;
        }
        return $this;
    }

    /** tls: names the certificate must cover. */
    public function dnsNames(string ...$names): self
    {
        $this->spec->dnsNames = array_values($names);
        return $this;
    }

    /** tls: allowed key algorithms: RSA, ECDSA, Ed25519. */
    public function keyAlgorithms(string ...$algorithms): self
    {
        $this->spec->keyAlgorithms = array_values($algorithms);
        return $this;
    }

    /** tls: the certificate must have at least this long left ("720h"). */
    public function minRemaining(string|Duration $duration): self
    {
        $this->spec->minRemaining = $duration instanceof Duration ? $duration : Duration::fromGo($duration);
        return $this;
    }

    /** tls: the directory holds ca.crt, and the certificate must chain to it. */
    public function requireCA(bool $require = true): self
    {
        $this->spec->requireCA = $require;
        return $this;
    }

    /** caBundle: at least this many certificates. */
    public function minCertificates(int $n): self
    {
        $this->spec->minCertificates = $n;
        return $this;
    }

    /** keystore: the secret variable that holds its password. */
    public function passwordVar(string $name): self
    {
        $this->spec->passwordVar = $name;
        return $this;
    }

    /** text: an RE2 pattern the content must match (anywhere, unless anchored). */
    public function pattern(string $re2): self
    {
        $this->spec->pattern = $re2;
        return $this;
    }

    public function minLength(int $n): self
    {
        $this->spec->minLength = $n;
        return $this;
    }

    public function maxLength(int $n): self
    {
        $this->spec->maxLength = $n;
        return $this;
    }

    /**
     * @internal
     * @param list<string> $problems
     */
    public function build(array &$problems): FileSpec
    {
        $this->applyDoc();
        if ($this->spec->class !== null && $this->spec->schema === null) {
            try {
                $this->spec->schema = SchemaGenerator::forClass($this->spec->class);
            } catch (DeclarationError $e) {
                array_push($problems, ...array_map(fn ($p) => "file {$this->spec->name}: $p", $e->problems));
            }
        }
        return $this->spec;
    }
}
