<?php

declare(strict_types=1);

namespace Docuconf\Export;

/**
 * Writes PHP data as CUE data: structs, lists, strings, numbers, bools and
 * null. No expressions, as SPEC §4 requires of a contract.
 *
 * Associative arrays are structs, lists are lists, and an empty `stdClass`
 * is the empty struct `{}`.
 */
final class Cue
{
    private const KEYWORDS = ['true', 'false', 'null', 'if', 'for', 'in', 'let', 'import', 'package', 'func', 'div', 'mod', 'quo', 'rem'];

    public static function value(mixed $value, int $indent = 0): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return self::float($value);
        }
        if (is_string($value)) {
            return self::string($value);
        }
        if ($value instanceof \Stringable) {
            return self::string((string) $value);
        }
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
            if ($value === []) {
                return '{}';
            }
        }
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }
            if (array_is_list($value)) {
                return self::list($value, $indent);
            }
            return self::struct($value, $indent);
        }
        throw new \InvalidArgumentException('cannot write ' . get_debug_type($value) . ' as CUE');
    }

    /** @param array<array-key, mixed> $fields */
    public static function struct(array $fields, int $indent): string
    {
        $pad = str_repeat("\t", $indent + 1);
        $out = "{\n";
        foreach ($fields as $key => $v) {
            $out .= $pad . self::label((string) $key) . ': ' . self::value($v, $indent + 1) . "\n";
        }
        return $out . str_repeat("\t", $indent) . '}';
    }

    /** @param list<mixed> $items */
    private static function list(array $items, int $indent): string
    {
        $scalars = array_filter($items, fn ($v) => !is_array($v) && !$v instanceof \stdClass);
        if (count($scalars) === count($items)) {
            return '[' . implode(', ', array_map(fn ($v) => self::value($v), $items)) . ']';
        }
        $pad = str_repeat("\t", $indent + 1);
        $out = "[\n";
        foreach ($items as $v) {
            $out .= $pad . self::value($v, $indent + 1) . ",\n";
        }
        return $out . str_repeat("\t", $indent) . ']';
    }

    public static function label(string $key): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $key) && !in_array($key, self::KEYWORDS, true)) {
            return $key;
        }
        return self::string($key);
    }

    public static function string(string $s): string
    {
        return json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** The shortest decimal that reads back as $f. */
    public static function float(float $f): string
    {
        if (!is_finite($f)) {
            throw new \InvalidArgumentException('CUE has no NaN or infinity');
        }
        $s = json_encode($f, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        return $s;
    }
}
