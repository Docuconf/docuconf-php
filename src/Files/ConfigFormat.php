<?php

declare(strict_types=1);

namespace Docuconf\Files;

use Docuconf\Schema\Json;

/**
 * Parses config files: JSON natively, YAML with symfony/yaml and TOML with
 * devium/toml, into JSON-shaped PHP data (see Json).
 *
 * @internal
 */
final class ConfigFormat
{
    /** @throws \Throwable when the content does not parse */
    public static function decode(string $format, string $data): mixed
    {
        switch ($format) {
            case 'json':
                return Json::decode($data);
            case 'yaml':
                $flags = \Symfony\Component\Yaml\Yaml::PARSE_OBJECT_FOR_MAP | \Symfony\Component\Yaml\Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE;
                return Json::normalize(\Symfony\Component\Yaml\Yaml::parse($data, $flags));
            case 'toml':
                return Json::normalize(self::tomlToPlain(\Devium\Toml\Toml::decode($data, true)));
        }
        throw new \InvalidArgumentException("unknown config format \"$format\"");
    }

    private static function tomlToPlain(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_RFC3339);
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            return array_map(self::tomlToPlain(...), $value);
        }
        return $value;
    }
}
