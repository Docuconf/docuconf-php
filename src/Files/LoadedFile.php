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
 * An input declared `reload: watch` (SPEC §4.6.2) is re-read when it
 * changes: reading `content` or `value` looks at the files at most once
 * per WATCH_INTERVAL seconds (Kubernetes swaps a symlink, which shows up
 * as a different file), and refresh() looks at once. A changed file that
 * fails its checks is not used: the previous content stays current, and
 * the failure is logged with error_log(), never the content.
 *
 * @property-read ?string $content the file's text, for config and text files
 * @property-read mixed $value the bound config object or data, or the text
 */
final class LoadedFile
{
    /** Seconds between looks at a watched input's files. */
    public const WATCH_INTERVAL = 1.0;

    /** @var (\Closure(): array{?LoadedFile, list<\Docuconf\Violation>})|null */
    private ?\Closure $reload = null;
    /** @var list<string> */
    private array $watched = [];
    /** @var list<?array<int|string, int>> */
    private array $stamps = [];
    private float $next = 0.0;

    public function __construct(
        public readonly string $name,
        public readonly string $path,
        #[\SensitiveParameter] ?string $content,
        #[\SensitiveParameter] mixed $value,
        public readonly bool $secret = false,
    ) {
        \Docuconf\Vault::put($this, ['content' => $content, 'value' => $value]);
    }

    /**
     * Re-reads the input with $reload when any of $paths changes.
     *
     * @internal
     * @param list<string> $paths
     * @param \Closure(): array{?LoadedFile, list<\Docuconf\Violation>} $reload
     */
    public function watch(array $paths, \Closure $reload): self
    {
        $this->watched = $paths;
        $this->stamps = self::stamps($paths);
        $this->reload = $reload;
        $this->next = microtime(true) + self::WATCH_INTERVAL;
        return $this;
    }

    /** Whether the input is declared `reload: watch`. */
    public function watched(): bool
    {
        return $this->reload !== null;
    }

    /**
     * For a watched input, looks at its files now and re-reads them if they
     * changed. Returns whether the content changed; false for an input
     * that is not watched, or a change that failed its checks.
     */
    public function refresh(): bool
    {
        if ($this->reload === null) {
            return false;
        }
        $this->next = microtime(true) + self::WATCH_INTERVAL;
        $stamps = self::stamps($this->watched);
        if ($stamps === $this->stamps) {
            return false;
        }
        $this->stamps = $stamps;
        [$fresh, $violations] = ($this->reload)();
        if ($fresh === null || $violations !== []) {
            $why = implode('; ', array_map(fn ($v) => "[{$v->code}] {$v->message}", $violations));
            error_log("docuconf: file {$this->name} changed, but the new content fails its checks, so the previous content stays in use: $why");
            return false;
        }
        \Docuconf\Vault::put($this, ['content' => $fresh->content, 'value' => $fresh->value]);
        return true;
    }

    public function __get(string $property): mixed
    {
        if ($this->reload !== null && microtime(true) >= $this->next) {
            $this->refresh();
        }
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

    /**
     * What identifies each file's current content: a projected volume swaps
     * a symlink, which stat() follows, so a swap is a different inode.
     *
     * @param list<string> $paths
     * @return list<?array<int|string, int>>
     */
    private static function stamps(array $paths): array
    {
        $out = [];
        foreach ($paths as $p) {
            clearstatcache(true, $p);
            $st = @stat($p);
            $out[] = $st === false ? null : ['dev' => $st['dev'], 'ino' => $st['ino'], 'size' => $st['size'], 'mtime' => $st['mtime'], 'ctime' => $st['ctime']];
        }
        return $out;
    }
}
