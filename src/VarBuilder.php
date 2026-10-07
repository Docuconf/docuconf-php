<?php

declare(strict_types=1);

namespace Docuconf;

use Docuconf\Schema\SchemaGenerator;
use Docuconf\Spec\VarSpec;

/**
 * Declares one variable, in phpdotenv's fluent style:
 *
 * ```php
 * $env->required('DATABASE_URL')->isUrl('postgres')->secret()->describe('Primary Postgres connection string');
 * $env->ifPresent('PORT')->isInteger()->between(1, 65535)->default(8080)->describe('HTTP listen port');
 * ```
 *
 * The type methods keep phpdotenv's names (`isInteger()`, `isBoolean()`,
 * `allowedValues()`, `notEmpty()`); the rest is what docuconf adds. Rules
 * are checked when the declaration is used, so the order of calls does not
 * matter.
 */
final class VarBuilder
{
    private mixed $rawDefault = null;
    private mixed $rawMin = null;
    private mixed $rawMax = null;
    /** @var class-string|null */
    private ?string $schemaClass = null;
    /** @var array<string, mixed>|null */
    private ?array $schemaArray = null;

    /** @internal */
    public function __construct(private readonly VarSpec $spec)
    {
    }

    /** @internal */
    public function spec(): VarSpec
    {
        return $this->spec;
    }

    // --- metadata -------------------------------------------------------

    /** What the variable is for; at least 5 characters. Required. */
    public function describe(string $description): self
    {
        $this->spec->description = $description;
        return $this;
    }

    /** The value must come from a secret, and is never printed. */
    public function secret(bool $secret = true): self
    {
        $this->spec->secret = $secret;
        return $this;
    }

    /** A heading for generated docs, such as "database" or "http". */
    public function group(string $group): self
    {
        $this->spec->group = $group;
        return $this;
    }

    /** Example values, for docs. */
    public function examples(string ...$examples): self
    {
        $this->spec->examples = array_values($examples);
        return $this;
    }

    /** The app's own configuration key, such as "orders.port" in config/orders.php. */
    public function configKey(string $key): self
    {
        $this->spec->configKey = $key;
        return $this;
    }

    /** Warns at boot when the variable is set. */
    public function deprecated(string $message, ?string $replacedBy = null): self
    {
        $this->spec->deprecated = $replacedBy === null ? ['message' => $message] : ['message' => $message, 'replacedBy' => $replacedBy];
        return $this;
    }

    /**
     * The value used when the variable is unset: an int, float, bool,
     * string, a Duration or Go duration string ("30s"), or a list.
     */
    public function default(mixed $value): self
    {
        $this->spec->hasDefault = true;
        $this->rawDefault = $value;
        return $this;
    }

    // --- types (phpdotenv names where it has one) ----------------------

    public function isString(): self
    {
        $this->spec->type = 'string';
        return $this;
    }

    public function isInteger(): self
    {
        $this->spec->type = 'int';
        return $this;
    }

    public function isFloat(): self
    {
        $this->spec->type = 'float';
        return $this;
    }

    public function isBoolean(): self
    {
        $this->spec->type = 'bool';
        return $this;
    }

    /**
     * A duration, as a Duration object. $encoding is how the env spells it:
     * "go" (1m30s), "iso8601" (PT90S), "seconds" (90) or "timespan" (00:01:30).
     */
    public function isDuration(string $encoding = 'go'): self
    {
        $this->spec->type = 'duration';
        $this->spec->encoding = $encoding;
        return $this;
    }

    /** A URL with a scheme, optionally limited to $schemes ("https", "postgres"). */
    public function isUrl(string ...$schemes): self
    {
        $this->spec->type = 'url';
        $this->spec->schemes = $schemes === [] ? null : array_values($schemes);
        return $this;
    }

    /**
     * One of a fixed set of strings (an enum).
     *
     * @param list<string>|class-string<\BackedEnum> $values the values, or a string-backed enum class
     */
    public function allowedValues(array|string $values): self
    {
        $this->spec->type = 'enum';
        if (is_string($values)) {
            $values = is_subclass_of($values, \BackedEnum::class)
                ? array_map(fn (\BackedEnum $c) => (string) $c->value, $values::cases())
                : [];
        }
        $this->spec->values = array_values(array_map('strval', $values));
        return $this;
    }

    /**
     * A list of strings or ints. $encoding is how the env spells it:
     * "csv" (a,b with $separator), "json" (["a","b"]) or "indexed"
     * (NAME__0=a, NAME__1=b).
     *
     * @param 'string'|'int' $items
     */
    public function isList(string $items = 'string', string $encoding = 'csv', string $separator = ','): self
    {
        $this->spec->type = 'list';
        $this->spec->items = $items;
        $this->spec->encoding = $encoding;
        $this->spec->separator = $separator;
        return $this;
    }

    /**
     * A structured JSON value. Give the app's own class to generate the
     * JSON Schema from it and get an instance back, or a JSON Schema array.
     *
     * @param class-string|array<string, mixed>|null $schema
     */
    public function isJson(string|array|null $schema = null): self
    {
        $this->spec->type = 'json';
        if (is_string($schema)) {
            /** @var class-string $schema */
            $this->schemaClass = $schema;
        } else {
            $this->schemaArray = $schema;
        }
        return $this;
    }

    // --- constraints ----------------------------------------------------

    /** phpdotenv's notEmpty(): a string of at least one character. */
    public function notEmpty(): self
    {
        $this->spec->minLength = max(1, $this->spec->minLength ?? 0);
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
     * An RE2 pattern, without delimiters. It matches anywhere in the value;
     * anchor it with ^ and $ to match the whole value.
     */
    public function pattern(string $re2): self
    {
        $this->spec->pattern = $re2;
        return $this;
    }

    /** Lower bound: an int, a float, or a duration ("1s" or a Duration). */
    public function min(int|float|string|Duration $min): self
    {
        $this->rawMin = $min;
        return $this;
    }

    /** Upper bound: an int, a float, or a duration ("5m" or a Duration). */
    public function max(int|float|string|Duration $max): self
    {
        $this->rawMax = $max;
        return $this;
    }

    public function between(int|float|string|Duration $min, int|float|string|Duration $max): self
    {
        return $this->min($min)->max($max);
    }

    /** Allowed URL schemes. */
    public function schemes(string ...$schemes): self
    {
        $this->spec->schemes = array_values($schemes);
        return $this;
    }

    public function minItems(int $n): self
    {
        $this->spec->minItems = $n;
        return $this;
    }

    public function maxItems(int $n): self
    {
        $this->spec->maxItems = $n;
        return $this;
    }

    /** Bounds on each item of an int list. */
    public function itemsBetween(?int $min, ?int $max): self
    {
        $this->spec->itemMin = $min;
        $this->spec->itemMax = $max;
        return $this;
    }

    // --- finishing -----------------------------------------------------

    /**
     * Converts the typed bits (durations, schema classes) and returns the
     * spec. Problems are reported by SpecValidator.
     *
     * @internal
     * @param list<string> $problems
     */
    public function build(array &$problems): VarSpec
    {
        $s = $this->spec;
        $name = $s->name;
        $duration = function (mixed $v, string $what) use ($name, &$problems): ?Duration {
            if ($v instanceof Duration) {
                return $v;
            }
            if (is_string($v)) {
                try {
                    return Duration::fromGo($v);
                } catch (\InvalidArgumentException $e) {
                    $problems[] = "$name: $what \"$v\" is not a Go duration such as 1m30s";
                    return null;
                }
            }
            if (is_int($v)) {
                $problems[] = "$name: $what for a duration must be a Go duration string, such as \"30s\", or a Duration";
            }
            return null;
        };
        if ($s->type === 'duration') {
            $s->min = $this->rawMin === null ? null : $duration($this->rawMin, 'min');
            $s->max = $this->rawMax === null ? null : $duration($this->rawMax, 'max');
            if ($s->hasDefault) {
                $s->default = $duration($this->rawDefault, 'default');
                if ($s->default === null) {
                    $s->hasDefault = false;
                }
            }
        } else {
            foreach (['min' => $this->rawMin, 'max' => $this->rawMax] as $f => $raw) {
                if (is_string($raw) || $raw instanceof Duration) {
                    $problems[] = "$name: $f must be a number for a {$s->type} variable";
                    $raw = null;
                }
                $s->{$f} = $s->type === 'float' && is_int($raw) ? (float) $raw : $raw;
            }
            if ($s->hasDefault) {
                $default = $this->rawDefault;
                if ($default instanceof \BackedEnum) {
                    $default = $default->value;
                }
                if ($s->type === 'float' && is_int($default)) {
                    $default = (float) $default;
                }
                if ($s->type === 'json' && is_object($default)) {
                    $default = Export\Exporter::plain($default);
                }
                $s->default = $default;
            }
        }
        if ($s->type === 'json') {
            if ($this->schemaClass !== null) {
                try {
                    $s->schema = SchemaGenerator::forClass($this->schemaClass);
                    $s->class = $this->schemaClass;
                } catch (DeclarationError $e) {
                    array_push($problems, ...array_map(fn ($p) => "$name: $p", $e->problems));
                }
            } else {
                $s->schema = $this->schemaArray;
            }
            if ($s->hasDefault && $s->class !== null && $s->default !== null) {
                // The default binds to the app's type too.
                try {
                    $s->default = Export\Exporter::plain($s->default);
                    Schema\Hydrator::hydrate($s->class, $s->default);
                } catch (Schema\HydrationError $e) {
                    $problems[] = "$name: default does not bind to {$s->class}: " . $e->getMessage();
                }
            }
        }
        return $s;
    }
}
