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
 * onChange() registers a callback that runs after a change passes its
 * checks and replaces the previous content, on the access (or refresh())
 * that sees it; status() says how many reloads were accepted and the last
 * one rejected.
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
    /** @var array<int, \Closure(LoadedFile): mixed> */
    private array $listeners = [];
    private int $listenerId = 0;
    private int $generation = 1;
    private ?\DateTimeImmutable $lastReload = null;
    private ?RejectedReload $lastRejected = null;

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
     * that is not watched, or a change that failed its checks. On-change
     * callbacks run before it returns.
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
            $codes = $violations === [] ? ['file_missing'] : array_values(array_unique(array_map(fn ($v) => $v->code, $violations)));
            $this->lastRejected = new RejectedReload(new \DateTimeImmutable(), $this->name, $codes);
            $why = $violations === [] ? '[file_missing] it is gone'
                : implode('; ', array_map(fn ($v) => "[{$v->code}] {$v->message}", $violations));
            error_log("docuconf: file {$this->name} changed, but the new content fails its checks, so the previous content stays in use: $why");
            return false;
        }
        \Docuconf\Vault::put($this, ['content' => $fresh->content, 'value' => $fresh->value]);
        $this->generation++;
        $this->lastReload = new \DateTimeImmutable();
        $this->lastRejected = null;
        foreach ($this->listeners as $listener) {
            try {
                $listener($this);
            } catch (\Throwable $e) {
                // The input's name and the error's type, never its message: it may quote the content.
                error_log("docuconf: an on-change callback for file {$this->name} threw " . get_class($e));
            }
        }
        return true;
    }

    /**
     * Calls $listener with this file after a change passes its checks and
     * replaces the previous content; read the new content from it, or open
     * the files at its path again (a TLS key pair, a keystore). Never
     * called for a change that fails its checks. Callbacks run in the
     * order they were added, on the read of `content` or `value` (or the
     * refresh()) that sees the change; one that throws is logged by the
     * input's name and the error's type, and the others still run.
     *
     * Returns a closure that removes the callback.
     *
     * @param callable(LoadedFile): mixed $listener
     * @return \Closure(): void
     */
    public function onChange(callable $listener): \Closure
    {
        if ($this->reload === null) {
            throw new \LogicException("docuconf: file {$this->name} is not declared reload: watch, so it never changes; declare ->reload('watch') to use onChange()");
        }
        $id = $this->listenerId++;
        $this->listeners[$id] = \Closure::fromCallable($listener);
        return function () use ($id): void {
            unset($this->listeners[$id]);
        };
    }

    /**
     * The reload status: the generation (1 after boot, plus one per
     * accepted reload), when the last reload was accepted, and the last
     * rejected change (cleared when a later one is accepted).
     */
    public function status(): ReloadStatus
    {
        return new ReloadStatus($this->generation, $this->lastReload, $this->lastRejected);
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
