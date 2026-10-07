<?php

declare(strict_types=1);

namespace Docuconf;

use Docuconf\Schema\Hydrator;
use Docuconf\Schema\HydrationError;
use Docuconf\Schema\JsonSchema;
use Docuconf\Spec\VarSpec;
use InvalidArgumentException;

/**
 * Reads one variable from an environment: its wire encoding (SPEC §5), its
 * type, and its constraints. The declaration path, the contract-first mode
 * and the declaration-time default check all go through this class.
 *
 * @internal
 */
final class VarParser
{
    /** Prefixes of injector references that were never resolved (SPEC §11.2). */
    private const UNRESOLVED_PREFIXES = ['vault:', 'op://', 'ref+'];

    private const URL_RE = '/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\/[^\s]+$/D';

    /**
     * Reads $spec from $env.
     *
     * @param array<string, string> $env
     * @return array{bool, mixed, ?Violation} [present, typed value or default, violation]
     */
    public static function read(VarSpec $spec, array $env): array
    {
        $name = $spec->name;
        try {
            $raw = self::raw($spec, $env);
        } catch (ParseFailure $f) {
            return [true, null, new Violation($name, $f->violationCode, $f->getMessage())];
        }
        // An empty string is a value for strings, and unset for every other type.
        if ($raw === null || ($raw === '' && $spec->type !== 'string')) {
            if ($spec->required) {
                return [false, null, new Violation($name, 'missing_required', 'required, but not set')];
            }
            return [false, $spec->hasDefault ? $spec->default : null, null];
        }
        if ($spec->secret && is_string($raw)) {
            foreach (self::UNRESOLVED_PREFIXES as $prefix) {
                if (str_starts_with($raw, $prefix)) {
                    return [true, null, new Violation(
                        $name,
                        'invalid_type',
                        "holds an unresolved \"$prefix\" reference: the injector that should replace it did not run",
                    )];
                }
            }
        }
        try {
            $value = self::parse($spec, $raw);
            $problem = self::check($spec, $value, $raw);
        } catch (ParseFailure $f) {
            return [true, null, new Violation($name, $f->violationCode, $f->getMessage())];
        }
        if ($problem !== null) {
            return [true, null, new Violation($name, $problem[0], $problem[1])];
        }
        if ($spec->type === 'json' && $spec->class !== null) {
            try {
                $value = Hydrator::hydrate($spec->class, $value);
            } catch (HydrationError $e) {
                return [true, null, new Violation($name, 'schema_mismatch', $e->getMessage())];
            }
        }
        return [true, $value, null];
    }

    /**
     * The raw value: a string, or for an indexed list the list of item strings.
     *
     * @param array<string, string> $env
     * @return string|list<string>|null
     * @throws ParseFailure
     */
    private static function raw(VarSpec $spec, array $env): string|array|null
    {
        if ($spec->type !== 'list' || $spec->encoding() !== 'indexed') {
            return $env[$spec->name] ?? null;
        }
        $prefix = $spec->name . '__';
        $items = [];
        foreach ($env as $key => $value) {
            $key = (string) $key;
            if (str_starts_with($key, $prefix)) {
                $suffix = substr($key, strlen($prefix));
                if (preg_match('/^(?:0|[1-9][0-9]{0,8})$/D', $suffix)) {
                    $items[(int) $suffix] = $value;
                } elseif (preg_match('/^[0-9]+$/D', $suffix)) {
                    // A leading zero (NAME__01) or a huge index is not an item number.
                    continue;
                }
            }
        }
        if ($items === []) {
            return null;
        }
        ksort($items);
        $indexes = array_keys($items);
        if ($indexes !== range(0, count($items) - 1)) {
            $found = implode(', ', array_map(fn (int $i) => $prefix . $i, $indexes));
            throw new ParseFailure(
                'invalid_type',
                "indexed list items must be numbered from {$prefix}0 with no gap; found $found",
            );
        }
        return array_values($items);
    }

    /**
     * Parses a raw value into its typed PHP form.
     *
     * @param string|list<string> $raw
     * @throws ParseFailure
     */
    public static function parse(VarSpec $spec, string|array $raw): mixed
    {
        if (is_array($raw)) {
            return array_map(fn (string $item) => self::item($spec, $item), $raw);
        }
        $got = self::got($spec, $raw);
        switch ($spec->type) {
            case 'string':
            case 'enum':
                return $raw;
            case 'url':
                if (!preg_match(self::URL_RE, $raw)) {
                    throw new ParseFailure('invalid_type', 'is not a URL with a scheme, such as https://host' . $got);
                }
                return $raw;
            case 'int':
                return self::int($raw, $got);
            case 'float':
                if (!preg_match('/^[+-]?(?:[0-9]+\.?[0-9]*|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $raw)) {
                    throw new ParseFailure('invalid_type', 'is not a number' . $got);
                }
                $f = (float) $raw;
                if (!is_finite($f)) {
                    throw new ParseFailure('invalid_type', 'is not a finite number' . $got);
                }
                return $f;
            case 'bool':
                // The platform sends true/false; phpdotenv's isBoolean() words are accepted too.
                return match (strtolower($raw)) {
                    'true', '1', 'yes', 'on' => true,
                    'false', '0', 'no', 'off' => false,
                    default => throw new ParseFailure('invalid_type', 'is not a boolean (true or false)' . $got),
                };
            case 'duration':
                $encoding = $spec->encoding() ?? 'go';
                try {
                    return Duration::parse($raw, $encoding);
                } catch (InvalidArgumentException $e) {
                    throw new ParseFailure('invalid_type', $e->getMessage() . " ($encoding encoding)" . $got);
                }
            case 'list':
                if ($spec->encoding() === 'json') {
                    $decoded = json_decode($raw, true, 64, JSON_BIGINT_AS_STRING);
                    if (!is_array($decoded) || !array_is_list($decoded)) {
                        throw new ParseFailure('invalid_type', 'is not a JSON array' . $got);
                    }
                    // Decoded again without JSON_BIGINT_AS_STRING, a number too big
                    // for 64 bits becomes a float, while a JSON string stays a string.
                    $plain = (array) json_decode($raw, true, 64);
                    $out = [];
                    foreach ($decoded as $i => $item) {
                        if (is_string($item) && is_float($plain[$i] ?? null)) {
                            throw new ParseFailure('out_of_range', 'has an item outside the 64-bit integer range');
                        }
                        $out[] = self::jsonItem($spec, $item, $got);
                    }
                    return $out;
                }
                $separator = $spec->separator === '' ? ',' : $spec->separator;
                return array_map(fn (string $item) => self::item($spec, $item), explode($separator, $raw));
            case 'json':
                try {
                    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                } catch (\JsonException $e) {
                    throw new ParseFailure('invalid_type', 'is not valid JSON: ' . $e->getMessage() . $got);
                }
        }
        throw new ParseFailure('invalid_type', "unknown type {$spec->type}");
    }

    /**
     * Checks a typed value against the variable's constraints.
     *
     * @return array{string, string}|null [code, message], or null when it passes
     */
    public static function check(VarSpec $spec, mixed $value, mixed $raw = null): ?array
    {
        $got = $spec->secret ? '' : ' (got ' . self::show($raw ?? $value) . ')';
        switch ($spec->type) {
            case 'string':
                if (!is_string($value)) {
                    return ['invalid_type', 'is not a string'];
                }
                $len = mb_strlen($value, 'UTF-8');
                if ($spec->minLength !== null && $len < $spec->minLength) {
                    return ['out_of_range', "is shorter than minLength {$spec->minLength} ($len characters)"];
                }
                if ($spec->maxLength !== null && $len > $spec->maxLength) {
                    return ['out_of_range', "is longer than maxLength {$spec->maxLength} ($len characters)"];
                }
                if ($spec->pattern !== null && !Re2::matches($spec->pattern, $value)) {
                    return ['pattern_mismatch', 'does not match pattern ' . $spec->pattern . $got];
                }
                return null;
            case 'int':
            case 'float':
                if (!is_int($value) && !($spec->type === 'float' && is_float($value))) {
                    return ['invalid_type', "is not an {$spec->type}"];
                }
                if ($spec->min !== null && !$spec->min instanceof Duration && $value < $spec->min) {
                    return ['out_of_range', 'must be at least ' . self::number($spec->min) . $got];
                }
                if ($spec->max !== null && !$spec->max instanceof Duration && $value > $spec->max) {
                    return ['out_of_range', 'must be at most ' . self::number($spec->max) . $got];
                }
                return null;
            case 'bool':
                return is_bool($value) ? null : ['invalid_type', 'is not a boolean'];
            case 'duration':
                if (!$value instanceof Duration) {
                    return ['invalid_type', 'is not a duration'];
                }
                $got = $spec->secret ? '' : " (got $value)";
                if ($spec->min instanceof Duration && $value->compare($spec->min) < 0) {
                    return ['out_of_range', "must be at least {$spec->min}$got"];
                }
                if ($spec->max instanceof Duration && $value->compare($spec->max) > 0) {
                    return ['out_of_range', "must be at most {$spec->max}$got"];
                }
                if (($spec->encoding() ?? 'go') !== 'go' && $value->nanoseconds % Duration::MILLISECOND !== 0) {
                    return ['invalid_type', 'has a precision finer than a millisecond, which its encoding cannot carry'];
                }
                return null;
            case 'url':
                if (!is_string($value) || !preg_match(self::URL_RE, $value)) {
                    return ['invalid_type', 'is not a URL with a scheme, such as https://host' . $got];
                }
                if ($spec->schemes !== null) {
                    $scheme = substr($value, 0, (int) strpos($value, '://'));
                    if (!in_array($scheme, $spec->schemes, true)) {
                        $allowed = implode(', ', $spec->schemes);
                        $shown = $spec->secret ? '' : " (got $scheme)";
                        return ['invalid_scheme', "scheme must be one of: $allowed$shown"];
                    }
                }
                return null;
            case 'enum':
                if (!is_string($value) || !in_array($value, $spec->values ?? [], true)) {
                    return ['not_in_enum', 'must be one of: ' . implode(', ', $spec->values ?? []) . $got];
                }
                return null;
            case 'list':
                if (!is_array($value) || !array_is_list($value)) {
                    return ['invalid_type', 'is not a list'];
                }
                foreach ($value as $item) {
                    if ($spec->items === 'int' ? !is_int($item) : !is_string($item)) {
                        return ['invalid_type', "has an item that is not an {$spec->items}"];
                    }
                    if ($spec->itemMin !== null && $item < $spec->itemMin) {
                        return ['out_of_range', "has an item below itemMin {$spec->itemMin}" . ($spec->secret ? '' : " ($item)")];
                    }
                    if ($spec->itemMax !== null && $item > $spec->itemMax) {
                        return ['out_of_range', "has an item above itemMax {$spec->itemMax}" . ($spec->secret ? '' : " ($item)")];
                    }
                }
                $n = count($value);
                if ($spec->minItems !== null && $n < $spec->minItems) {
                    return ['too_few_items', "has $n items, fewer than minItems {$spec->minItems}"];
                }
                if ($spec->maxItems !== null && $n > $spec->maxItems) {
                    return ['too_many_items', "has $n items, more than maxItems {$spec->maxItems}"];
                }
                return null;
            case 'json':
                if ($spec->schema !== null) {
                    $error = JsonSchema::validate($spec->schema, $value, is_string($raw) ? $raw : null);
                    if ($error !== null) {
                        return ['schema_mismatch', 'does not match its schema: ' . $error];
                    }
                }
                return null;
        }
        return ['invalid_type', "unknown type {$spec->type}"];
    }

    /** @throws ParseFailure */
    private static function item(VarSpec $spec, string $item): string|int
    {
        if ($spec->items !== 'int') {
            return $item;
        }
        return self::int($item, $spec->secret ? '' : ' (item ' . self::show($item) . ')', 'has an item that is not an integer');
    }

    /** @throws ParseFailure */
    private static function jsonItem(VarSpec $spec, mixed $item, string $got): string|int
    {
        if ($spec->items === 'string') {
            if (!is_string($item)) {
                throw new ParseFailure('invalid_type', 'has an item that is not a string' . $got);
            }
            return $item;
        }
        if (!is_int($item)) {
            throw new ParseFailure('invalid_type', 'has an item that is not an integer' . $got);
        }
        return $item;
    }

    /** @throws ParseFailure */
    private static function int(string $raw, string $got, string $what = 'is not an integer'): int
    {
        if (!preg_match('/^([+-]?)0*([0-9]+)$/D', $raw, $m)) {
            throw new ParseFailure('invalid_type', $what . $got);
        }
        $digits = $m[2];
        $limit = $m[1] === '-' ? '9223372036854775808' : '9223372036854775807';
        if (strlen($digits) > 19 || (strlen($digits) === 19 && strcmp($digits, $limit) > 0)) {
            throw new ParseFailure('out_of_range', 'is outside the 64-bit integer range' . $got);
        }
        if ($m[1] === '-' && $digits === '9223372036854775808') {
            return PHP_INT_MIN;
        }
        return (int) ($m[1] . $digits);
    }

    private static function got(VarSpec $spec, string $raw): string
    {
        return $spec->secret ? '' : ' (got ' . self::show($raw) . ')';
    }

    private static function show(mixed $value): string
    {
        if ($value instanceof Duration) {
            return (string) $value;
        }
        if (!is_string($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '?';
            return mb_strlen($value) > 60 ? mb_substr($value, 0, 57) . '...' : $value;
        }
        if (mb_strlen($value) > 60) {
            $value = mb_substr($value, 0, 57) . '...';
        }
        return '"' . addcslashes($value, "\"\\\n\r\t") . '"';
    }

    private static function number(int|float $n): string
    {
        return is_int($n) ? (string) $n : Export\Cue::float($n);
    }
}
