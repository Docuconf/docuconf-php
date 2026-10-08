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
 * itself, from `path`. The content of a secret file never shows in
 * var_dump(), print_r(), var_export() or VarDumper.
 *
 * @property-read ?string $content the file's text, for config and text files
 * @property-read mixed $value the bound config object or data, or the text
 */
final class LoadedFile
{
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        #[\SensitiveParameter] ?string $content,
        #[\SensitiveParameter] mixed $value,
        public readonly bool $secret = false,
    ) {
        \Docuconf\Vault::put($this, ['content' => $content, 'value' => $value]);
    }

    public function __get(string $property): mixed
    {
        $data = \Docuconf\Vault::get($this);
        if (!is_array($data) || !array_key_exists($property, $data)) {
            throw new \LogicException("docuconf: LoadedFile has no property \"$property\"");
        }
        return $data[$property];
    }

    public function __isset(string $property): bool
    {
        return in_array($property, ['content', 'value'], true) && $this->__get($property) !== null;
    }

    public function __clone()
    {
        throw new \LogicException('docuconf: a LoadedFile is immutable; there is no need to clone it');
    }

    /**
     * What var_dump(), print_r() and VarDumper show; the content of a secret
     * file is redacted.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'name' => $this->name,
            'path' => $this->path,
            'content' => $this->secret && $this->content !== null ? \Docuconf\Values::REDACTED : $this->content,
            'value' => $this->secret && $this->value !== null ? \Docuconf\Values::REDACTED : $this->value,
        ];
    }

    /** A path inside a TLS key pair directory: tls.crt, tls.key or ca.crt. */
    public function file(string $name): string
    {
        return $this->path . '/' . $name;
    }
}
