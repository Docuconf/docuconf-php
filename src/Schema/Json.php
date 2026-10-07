<?php

declare(strict_types=1);

namespace Docuconf\Schema;

/**
 * JSON values as PHP data: objects are associative arrays, except an empty
 * object, which stays a `stdClass` so it is not confused with `[]`.
 *
 * @internal
 */
final class Json
{
    /** Decodes JSON into that form. */
    public static function decode(string $json): mixed
    {
        return self::normalize(json_decode($json, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING));
    }

    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $vars = get_object_vars($value);
            if ($vars === []) {
                return $value;
            }
            return array_map(self::normalize(...), $vars);
        }
        if (is_array($value)) {
            return array_map(self::normalize(...), $value);
        }
        return $value;
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
