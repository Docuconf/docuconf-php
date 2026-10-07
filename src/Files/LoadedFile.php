<?php

declare(strict_types=1);

namespace Docuconf\Files;

/**
 * A file input that passed its boot checks.
 *
 * `path` is where it was found (after `pathEnv` and DOCUCONF_FILE_ROOT);
 * for a TLS key pair it is the directory. `value` is the bound config
 * object or data for a config file, the text for a text file, and null
 * otherwise: the app opens TLS pairs, CA bundles, keystores and binaries
 * itself, from `path`.
 */
final class LoadedFile
{
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly ?string $content,
        public readonly mixed $value,
    ) {
    }

    /** A path inside a TLS key pair directory: tls.crt, tls.key or ca.crt. */
    public function file(string $name): string
    {
        return $this->path . '/' . $name;
    }
}
