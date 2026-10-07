<?php

declare(strict_types=1);

namespace Docuconf\Laravel;

use Docuconf\Declaration;
use Docuconf\DeclarationError;
use Docuconf\Duration;
use Docuconf\Environment;
use Docuconf\Export\Exporter;
use Docuconf\VarBuilder;
use Docuconf\VarParser;

/**
 * Typed, documented `env()` for Laravel config files.
 *
 * Where a config file says `'port' => env('PORT', 8080)`, it says
 *
 * ```php
 * 'port' => Env::int('PORT', 'HTTP listen port', default: 8080, min: 1, max: 65535),
 * ```
 *
 * The call returns the typed value, exactly where `env()` did, and records
 * the declaration. When the app boots, DocuconfServiceProvider checks every
 * recorded variable against the real environment and refuses to start with
 * a one-line-per-variable report; `php artisan docuconf:export` writes them
 * as the service's contract.
 *
 * A value that fails its rules returns its default (or null) here; the
 * boot check is what reports it, and commands that skip the check print a
 * warning.
 *
 * Secret values come back as plain strings, as env() gives them, so
 * `php artisan config:show` prints them like any env-backed config.
 */
final class Env
{
    /** @var array<string, \Closure(Declaration): void> */
    private static array $vars = [];
    /** @var list<\Closure(Declaration): void> */
    private static array $extra = [];
    /** @var array<string, string> what each call returned, hashed */
    private static array $fingerprints = [];

    public static function string(
        string $name,
        string $description,
        ?string $default = null,
        bool $required = false,
        bool $secret = false,
        ?int $minLength = null,
        ?int $maxLength = null,
        ?string $pattern = null,
        ?string $group = null,
    ): ?string {
        /** @var ?string */
        return self::declareVar($name, $description, $required, $secret, $group, $default, function (VarBuilder $v) use ($minLength, $maxLength, $pattern) {
            $v->isString();
            if ($minLength !== null) {
                $v->minLength($minLength);
            }
            if ($maxLength !== null) {
                $v->maxLength($maxLength);
            }
            if ($pattern !== null) {
                $v->pattern($pattern);
            }
        });
    }

    public static function int(
        string $name,
        string $description,
        ?int $default = null,
        bool $required = false,
        ?int $min = null,
        ?int $max = null,
        bool $secret = false,
        ?string $group = null,
    ): ?int {
        /** @var ?int */
        return self::declareVar($name, $description, $required, $secret, $group, $default, function (VarBuilder $v) use ($min, $max) {
            $v->isInteger();
            if ($min !== null) {
                $v->min($min);
            }
            if ($max !== null) {
                $v->max($max);
            }
        });
    }

    public static function float(
        string $name,
        string $description,
        ?float $default = null,
        bool $required = false,
        ?float $min = null,
        ?float $max = null,
        bool $secret = false,
        ?string $group = null,
    ): ?float {
        /** @var ?float */
        return self::declareVar($name, $description, $required, $secret, $group, $default, function (VarBuilder $v) use ($min, $max) {
            $v->isFloat();
            if ($min !== null) {
                $v->min($min);
            }
            if ($max !== null) {
                $v->max($max);
            }
        });
    }

    public static function bool(
        string $name,
        string $description,
        ?bool $default = null,
        bool $required = false,
        ?string $group = null,
    ): ?bool {
        /** @var ?bool */
        return self::declareVar($name, $description, $required, false, $group, $default, fn (VarBuilder $v) => $v->isBoolean());
    }

    /**
     * @param string $encoding how the env spells it: go (30s), iso8601 (PT30S), seconds (30), timespan (00:00:30)
     */
    public static function duration(
        string $name,
        string $description,
        string|Duration|null $default = null,
        bool $required = false,
        string|Duration|null $min = null,
        string|Duration|null $max = null,
        string $encoding = 'go',
        bool $secret = false,
        ?string $group = null,
    ): ?Duration {
        /** @var ?Duration */
        return self::declareVar($name, $description, $required, $secret, $group, $default, function (VarBuilder $v) use ($min, $max, $encoding) {
            $v->isDuration($encoding);
            if ($min !== null) {
                $v->min($min);
            }
            if ($max !== null) {
                $v->max($max);
            }
        });
    }

    /** @param list<string> $schemes */
    public static function url(
        string $name,
        string $description,
        ?string $default = null,
        bool $required = false,
        array $schemes = [],
        bool $secret = false,
        ?string $group = null,
    ): ?string {
        /** @var ?string */
        return self::declareVar($name, $description, $required, $secret, $group, $default, fn (VarBuilder $v) => $v->isUrl(...$schemes));
    }

    /**
     * @param list<string>|class-string<\BackedEnum> $values the allowed values, or a string-backed enum class
     */
    public static function enum(
        string $name,
        string $description,
        array|string $values,
        ?string $default = null,
        bool $required = false,
        ?string $group = null,
    ): ?string {
        /** @var ?string */
        return self::declareVar($name, $description, $required, false, $group, $default, fn (VarBuilder $v) => $v->allowedValues($values));
    }

    /**
     * @param list<string|int>|null $default
     * @param 'string'|'int' $items
     * @param string $encoding csv (a,b), json (["a","b"]) or indexed (NAME__0, NAME__1)
     * @return list<string|int>|null
     */
    public static function list(
        string $name,
        string $description,
        ?array $default = null,
        bool $required = false,
        string $items = 'string',
        string $encoding = 'csv',
        string $separator = ',',
        ?int $minItems = null,
        ?int $maxItems = null,
        ?int $itemMin = null,
        ?int $itemMax = null,
        ?string $group = null,
    ): ?array {
        /** @var list<string|int>|null */
        return self::declareVar($name, $description, $required, false, $group, $default, function (VarBuilder $v) use ($items, $encoding, $separator, $minItems, $maxItems, $itemMin, $itemMax) {
            $v->isList($items, $encoding, $separator);
            if ($minItems !== null) {
                $v->minItems($minItems);
            }
            if ($maxItems !== null) {
                $v->maxItems($maxItems);
            }
            if ($itemMin !== null || $itemMax !== null) {
                $v->itemsBetween($itemMin, $itemMax);
            }
        });
    }

    /**
     * A structured JSON value; with a class, its JSON Schema is generated
     * from the class and the value is an instance of it.
     *
     * @param class-string|array<string, mixed>|null $schema
     */
    public static function json(
        string $name,
        string $description,
        string|array|null $schema = null,
        mixed $default = null,
        bool $required = false,
        bool $secret = false,
        ?string $group = null,
    ): mixed {
        return self::declareVar($name, $description, $required, $secret, $group, $default, fn (VarBuilder $v) => $v->isJson($schema));
    }

    /**
     * Anything the typed helpers do not cover, such as file inputs, with the
     * fluent API:
     *
     * ```php
     * Env::declare(fn (Declaration $env) => $env->tls('serving-tls', '/etc/orders/tls')->describe('Serving certificate'));
     * ```
     *
     * @param \Closure(Declaration): mixed $declare
     */
    public static function declare(\Closure $declare): void
    {
        self::$extra[] = function (Declaration $d) use ($declare): void {
            $declare($d);
        };
    }

    /**
     * Every variable recorded so far, as a declaration for service $name.
     */
    public static function declaration(string $name, ?string $appVersion = null): Declaration
    {
        $d = new Declaration($name, $appVersion);
        foreach (self::$vars as $apply) {
            $apply($d);
        }
        foreach (self::$extra as $apply) {
            $apply($d);
        }
        return $d;
    }

    /** Whether anything has been declared (false when config is cached and the files were not run). */
    public static function hasDeclarations(): bool
    {
        return self::$vars !== [] || self::$extra !== [];
    }

    /** Forgets every declaration (between tests, or before re-reading config files). */
    public static function flush(): void
    {
        self::$vars = [];
        self::$extra = [];
        self::$fingerprints = [];
    }

    /**
     * A hash of what each Env:: call returned, by name. `config:cache`
     * stores it, so the boot check can tell when cached values are not what
     * the environment gives now. Hashed, so secrets do not show in
     * `config:show docuconf`.
     *
     * @return array<string, string>
     */
    public static function fingerprints(): array
    {
        return self::$fingerprints;
    }

    /** @internal */
    public static function fingerprint(mixed $value): string
    {
        return hash('sha256', serialize(Exporter::plain($value)));
    }

    /**
     * @param \Closure(VarBuilder): mixed $type
     */
    private static function declareVar(
        string $name,
        string $description,
        bool $required,
        bool $secret,
        ?string $group,
        mixed $default,
        \Closure $type,
    ): mixed {
        $apply = function (Declaration $d) use ($name, $description, $required, $secret, $group, $default, $type): void {
            $v = $required ? $d->required($name) : $d->ifPresent($name);
            $v->describe($description)->secret($secret);
            $type($v);
            if ($group !== null) {
                $v->group($group);
            }
            if ($default !== null) {
                $v->default($default);
            }
        };
        self::$vars[$name] = $apply;

        // Check this one declaration now, so a mistake points at its config file.
        $single = new Declaration('config');
        try {
            $apply($single);
            $spec = $single->spec()->vars[$name] ?? throw new DeclarationError(["$name: not declared"]);
        } catch (DeclarationError $e) {
            $at = self::callSite();
            throw new DeclarationError(array_map(fn (string $p) => $at . $p, $e->problems));
        }
        [, $value, $violation] = VarParser::read($spec, Environment::capture());
        if ($violation !== null) {
            $value = $spec->hasDefault ? $spec->default : null;
        }
        self::$fingerprints[$name] = self::fingerprint($value);
        return $value;
    }

    /** "config/orders.php:10: ", the config file line that called Env::. */
    private static function callSite(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $frame) {
            $file = $frame['file'] ?? null;
            if ($file === null || $file === __FILE__) {
                continue;
            }
            try {
                $base = function_exists('base_path') ? rtrim((string) base_path(), '/') . '/' : '';
            } catch (\Throwable) {
                $base = '';
            }
            if ($base !== '' && $base !== '/' && str_starts_with($file, $base)) {
                $file = substr($file, strlen($base));
            }
            return $file . ':' . ($frame['line'] ?? 0) . ': ';
        }
        return '';
    }
}
