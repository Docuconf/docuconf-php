<?php

declare(strict_types=1);

namespace Docuconf;

use ArrayAccess;
use Docuconf\Export\Exporter;
use Docuconf\Files\LoadedFile;
use Docuconf\Spec\ContractSpec;
use IteratorAggregate;
use JsonSerializable;
use LogicException;
use Traversable;

/**
 * The typed values of a loaded declaration, keyed by variable name.
 *
 * Typed accessors (`int()`, `duration()`, `list()`, ...) return the value
 * in its PHP type, or null for an optional variable that is not set. Read
 * access by `$values['PORT']` works too. `redacted()` (and json_encode)
 * show every secret as "***".
 *
 * @implements ArrayAccess<string, mixed>
 * @implements IteratorAggregate<string, mixed>
 */
final class Values implements ArrayAccess, IteratorAggregate, JsonSerializable
{
    public const REDACTED = '***';

    /**
     * @param array<string, mixed> $values
     * @param array<string, bool> $present
     * @param array<string, ?LoadedFile> $files
     */
    public function __construct(
        private readonly ContractSpec $spec,
        private readonly array $values,
        private readonly array $present,
        private readonly array $files = [],
    ) {
    }

    public function get(string $name): mixed
    {
        $this->known($name);
        return $this->values[$name];
    }

    /** Whether the environment set the variable (rather than a default applying). */
    public function isSet(string $name): bool
    {
        $this->known($name);
        return $this->present[$name] ?? false;
    }

    public function string(string $name): ?string
    {
        return $this->typed($name, 'string', fn ($v) => is_string($v));
    }

    public function int(string $name): ?int
    {
        return $this->typed($name, 'int', fn ($v) => is_int($v));
    }

    public function float(string $name): ?float
    {
        $v = $this->typed($name, 'float', fn ($v) => is_float($v) || is_int($v));
        return $v === null ? null : (float) $v;
    }

    public function bool(string $name): ?bool
    {
        return $this->typed($name, 'bool', fn ($v) => is_bool($v));
    }

    public function duration(string $name): ?Duration
    {
        return $this->typed($name, 'duration', fn ($v) => $v instanceof Duration);
    }

    /** @return list<string|int>|null */
    public function list(string $name): ?array
    {
        return $this->typed($name, 'list', fn ($v) => is_array($v));
    }

    /** A json variable's value: decoded JSON, or the app's type when declared with a class. */
    public function json(string $name): mixed
    {
        return $this->get($name);
    }

    /** A file input that passed its checks, or null when an optional one is absent. */
    public function file(string $name): ?LoadedFile
    {
        if (!array_key_exists($name, $this->files)) {
            throw new LogicException("docuconf: \"$name\" is not a declared file input");
        }
        return $this->files[$name];
    }

    /**
     * Every variable's typed value, keyed by name.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * Every variable as plain JSON-ready data, with secrets shown as "***"
     * and durations in canonical Go form. Safe to log or serve.
     *
     * @return array<string, mixed>
     */
    public function redacted(): array
    {
        $out = [];
        foreach ($this->values as $name => $value) {
            $var = $this->spec->vars[$name];
            $out[$name] = $var->secret && $value !== null ? self::REDACTED : Exporter::plain($value);
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->redacted();
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && array_key_exists($offset, $this->values) && $this->values[$offset] !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('docuconf: configuration values are read-only');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('docuconf: configuration values are read-only');
    }

    public function getIterator(): Traversable
    {
        yield from $this->values;
    }

    /**
     * @param callable(mixed): bool $is
     */
    private function typed(string $name, string $type, callable $is): mixed
    {
        $v = $this->get($name);
        if ($v === null) {
            return null;
        }
        if (!$is($v)) {
            $declared = $this->spec->vars[$name]->type;
            throw new LogicException("docuconf: $name is a $declared variable, not a $type");
        }
        return $v;
    }

    private function known(string $name): void
    {
        if (!array_key_exists($name, $this->values)) {
            throw new LogicException("docuconf: \"$name\" is not a declared variable");
        }
    }
}
