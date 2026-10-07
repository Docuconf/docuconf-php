<?php

declare(strict_types=1);

namespace Docuconf;

use ArrayAccess;
use Docuconf\Export\Exporter;
use Docuconf\Files\LoadedFile;
use Docuconf\Spec\ContractSpec;
use Docuconf\Spec\SpecValidator;
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
        #[\SensitiveParameter] array $values,
        private readonly array $present,
        #[\SensitiveParameter] array $files = [],
    ) {
        // Held outside the object (see Vault), so that no debug printer can
        // reach a secret; var_dump(), print_r() and VarDumper show __debugInfo().
        Vault::put($this, ['values' => $values, 'files' => $files]);
    }

    public function __clone()
    {
        throw new LogicException('docuconf: configuration values are immutable; there is no need to clone them');
    }

    /** @return array{values: array<string, mixed>, files: array<string, ?LoadedFile>} */
    private function data(): array
    {
        /** @var array{values: array<string, mixed>, files: array<string, ?LoadedFile>} */
        return Vault::get($this);
    }

    public function get(string $name): mixed
    {
        $this->known($name);
        return $this->data()['values'][$name];
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
        $files = $this->data()['files'];
        if (!array_key_exists($name, $files)) {
            throw new LogicException("docuconf: \"$name\" is not a declared file input");
        }
        return $files[$name];
    }

    /**
     * A secret variable's value, wrapped so that it never prints: echo,
     * var_dump, print_r, var_export, json_encode and dump() show "***".
     * Call `->reveal()` where the real value is needed. Null when unset.
     */
    public function secret(string $name): ?Secret
    {
        $v = $this->get($name);
        if (!$this->spec->vars[$name]->secret) {
            throw new LogicException("docuconf: $name is not declared secret(); read it with get() or a typed accessor");
        }
        return $v === null ? null : new Secret($v);
    }

    /**
     * Binds the values to a readonly class, by constructor parameter: `$port`
     * reads PORT, `$databaseUrl` reads DATABASE_URL, and `#[FromEnv('NAME')]`
     * names any other variable. A parameter typed `Secret` gets the value
     * wrapped; one typed `\DateInterval` gets a duration as an interval.
     *
     * ```php
     * final class OrdersConfig
     * {
     *     public function __construct(
     *         public readonly int $port,
     *         public readonly Secret $databaseUrl,
     *         public readonly Duration $requestTimeout,
     *     ) {}
     * }
     * $orders = $env->loadOrExit()->bind(OrdersConfig::class);
     * ```
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function bind(string $class): object
    {
        $r = new \ReflectionClass($class);
        $ctor = $r->getConstructor();
        if ($ctor === null) {
            throw new LogicException("docuconf: cannot bind to $class: it has no constructor");
        }
        $values = $this->data()['values'];
        $args = [];
        $problems = [];
        foreach ($ctor->getParameters() as $p) {
            $param = '$' . $p->getName();
            $attr = $p->getAttributes(FromEnv::class)[0] ?? null;
            $name = $attr !== null ? $attr->newInstance()->name : self::envName($p->getName());
            if (!array_key_exists($name, $values)) {
                $problems[] = "$param: $name is not a declared variable; declare it, or name the variable with #[Docuconf\\FromEnv('NAME')]";
                continue;
            }
            $value = $values[$name];
            $type = $p->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            if ($value === null) {
                if ($p->isDefaultValueAvailable()) {
                    $args[$p->getName()] = $p->getDefaultValue();
                    continue;
                }
                if ($type !== null && !$type->allowsNull()) {
                    $problems[] = "$param: $name is not set and has no default; make the parameter nullable or give it a default";
                    continue;
                }
            } elseif ($typeName === Secret::class) {
                $value = new Secret($value);
            } elseif ($typeName === \DateInterval::class && $value instanceof Duration) {
                $value = $value->toDateInterval();
            } elseif ($typeName === 'float' && is_int($value)) {
                $value = (float) $value;
            }
            $args[$p->getName()] = $value;
        }
        if ($problems !== []) {
            throw new LogicException("docuconf: cannot bind to $class:\n  - " . implode("\n  - ", $problems));
        }
        try {
            return $r->newInstanceArgs($args);
        } catch (\TypeError $e) {
            $msg = $e->getMessage();
            foreach ($this->spec->vars as $var) {
                if ($var->secret && is_string($values[$var->name] ?? null) && $values[$var->name] !== '') {
                    $msg = str_replace($values[$var->name], self::REDACTED, $msg);
                }
            }
            throw new LogicException("docuconf: cannot bind to $class: $msg");
        }
    }

    /** "databaseUrl" becomes "DATABASE_URL". */
    private static function envName(string $param): string
    {
        return strtoupper((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $param));
    }

    /**
     * Every variable's typed value, keyed by name.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data()['values'];
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
        foreach ($this->data()['values'] as $name => $value) {
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

    /**
     * What var_dump(), print_r() and Symfony's VarDumper (Laravel's dump()
     * and dd()) show: the redacted values, never a secret.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->redacted();
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('docuconf: configuration values cannot be serialized, so that secrets never reach a cache; serialize redacted() instead');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('docuconf: configuration values cannot be unserialized');
    }

    public function offsetExists(mixed $offset): bool
    {
        $values = $this->data()['values'];
        return is_string($offset) && array_key_exists($offset, $values) && $values[$offset] !== null;
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
        yield from $this->data()['values'];
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
            throw new LogicException("docuconf: $name is " . SpecValidator::article($declared) . " $declared variable, not " . SpecValidator::article($type) . " $type");
        }
        return $v;
    }

    private function known(string $name): void
    {
        if (!array_key_exists($name, $this->data()['values'])) {
            throw new LogicException("docuconf: \"$name\" is not a declared variable");
        }
    }
}
