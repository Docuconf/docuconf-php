<?php

declare(strict_types=1);

namespace Docuconf\Schema;

use BackedEnum;
use Docuconf\Duration;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Binds decoded config data (a config file, a `json` variable) to the
 * app's own type: constructor arguments by name, nested classes, lists of
 * classes, backed enums and Durations. It is strict, as the generated
 * schema is: a missing required property, a property of the wrong type or
 * an unknown property is an error.
 */
final class Hydrator
{
    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     * @throws HydrationError
     */
    public static function hydrate(string $class, mixed $data, string $path = ''): object
    {
        if (!is_array($data) && !$data instanceof \stdClass) {
            throw new HydrationError(self::at($path) . 'expected an object');
        }
        $data = (array) $data;
        if ($data !== [] && array_is_list($data)) {
            throw new HydrationError(self::at($path) . 'expected an object, got a list');
        }
        $props = ClassShape::properties($class);
        $known = [];
        $args = [];
        foreach ($props as $p) {
            $known[$p['key']] = true;
            $sub = $path === '' ? $p['key'] : $path . '.' . $p['key'];
            if (!array_key_exists($p['key'], $data)) {
                if (!$p['optional']) {
                    throw new HydrationError(self::at($sub) . 'required, but missing');
                }
                $args[$p['param']] = $p['default'];
                continue;
            }
            $args[$p['param']] = self::value($p['type'], $p['listOf'], $data[$p['key']], $sub);
        }
        foreach (array_keys($data) as $key) {
            if (!isset($known[(string) $key])) {
                $sub = $path === '' ? (string) $key : $path . '.' . $key;
                throw new HydrationError(self::at($sub) . 'is not a property of ' . $class);
            }
        }
        $ref = new ReflectionClass($class);
        if ($ref->getConstructor() !== null) {
            return $ref->newInstanceArgs($args);
        }
        $object = $ref->newInstance();
        foreach ($args as $name => $value) {
            $object->{$name} = $value;
        }
        return $object;
    }

    private static function value(?\ReflectionType $type, ?string $listOf, mixed $value, string $path): mixed
    {
        [$named, $nullable] = ClassShape::split($type);
        if ($value === null) {
            if ($nullable) {
                return null;
            }
            throw new HydrationError(self::at($path) . 'must not be null');
        }
        if ($named === []) {
            return $value;
        }
        $last = new HydrationError(self::at($path) . 'has the wrong type');
        foreach ($named as $t) {
            try {
                return self::named($t, $listOf, $value, $path);
            } catch (HydrationError $e) {
                $last = $e;
            }
        }
        throw $last;
    }

    private static function named(ReflectionNamedType $t, ?string $listOf, mixed $value, string $path): mixed
    {
        $name = $t->getName();
        $ok = match ($name) {
            'int' => is_int($value),
            'float' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            'mixed' => true,
            default => null,
        };
        if ($ok === true) {
            return $name === 'float' ? (float) $value : $value;
        }
        if ($ok === false) {
            throw new HydrationError(self::at($path) . "must be a $name, got " . get_debug_type($value));
        }
        if ($name === 'array' || $name === 'iterable') {
            if (!is_array($value) && !$value instanceof \stdClass) {
                throw new HydrationError(self::at($path) . 'must be an array, got ' . get_debug_type($value));
            }
            $value = (array) $value;
            if ($listOf === null) {
                return $value;
            }
            if (!array_is_list($value)) {
                throw new HydrationError(self::at($path) . 'must be a list');
            }
            $out = [];
            foreach ($value as $i => $item) {
                $out[] = self::item($listOf, $item, "{$path}[$i]");
            }
            return $out;
        }
        return self::item($name, $value, $path);
    }

    private static function item(string $type, mixed $value, string $path): mixed
    {
        $ok = match ($type) {
            'int' => is_int($value),
            'float' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            default => null,
        };
        if ($ok !== null) {
            if (!$ok) {
                throw new HydrationError(self::at($path) . "must be a $type, got " . get_debug_type($value));
            }
            return $type === 'float' ? (float) $value : $value;
        }
        if ($type === Duration::class) {
            if (!is_string($value)) {
                throw new HydrationError(self::at($path) . 'must be a duration string such as 1m30s');
            }
            try {
                return Duration::fromGo($value);
            } catch (\InvalidArgumentException $e) {
                throw new HydrationError(self::at($path) . $e->getMessage());
            }
        }
        if (is_subclass_of($type, BackedEnum::class)) {
            if (!is_int($value) && !is_string($value)) {
                throw new HydrationError(self::at($path) . 'must be one of the values of ' . $type);
            }
            $case = $type::tryFrom($value);
            if ($case === null) {
                throw new HydrationError(self::at($path) . 'must be one of the values of ' . $type);
            }
            return $case;
        }
        if (enum_exists($type)) {
            foreach ($type::cases() as $case) {
                if ($case->name === $value) {
                    return $case;
                }
            }
            throw new HydrationError(self::at($path) . 'must be one of the cases of ' . $type);
        }
        if (class_exists($type)) {
            return self::hydrate($type, $value, $path);
        }
        return $value;
    }

    private static function at(string $path): string
    {
        return $path === '' ? '' : "$path: ";
    }
}
