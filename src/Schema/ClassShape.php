<?php

declare(strict_types=1);

namespace Docuconf\Schema;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;

/**
 * The properties of a config type, read by reflection: the constructor's
 * parameters when it has a constructor (promoted or not), its public
 * properties otherwise.
 *
 * @internal
 */
final class ClassShape
{
    /**
     * @param class-string $class
     * @return list<array{key: string, param: string, type: ?ReflectionType, optional: bool, default: mixed, field: ?Field, listOf: ?string}>
     */
    public static function properties(string $class): array
    {
        $ref = new ReflectionClass($class);
        $ctor = $ref->getConstructor();
        $out = [];
        if ($ctor !== null && $ctor->isPublic()) {
            $docTypes = self::docListTypes((string) $ctor->getDocComment(), '@param');
            foreach ($ctor->getParameters() as $p) {
                $out[] = self::describe($p, $docTypes[$p->getName()] ?? null, $ref);
            }
            return $out;
        }
        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $p) {
            if ($p->isStatic()) {
                continue;
            }
            $docTypes = self::docListTypes((string) $p->getDocComment(), '@var');
            $out[] = self::describe($p, $docTypes[''] ?? null, $ref);
        }
        return $out;
    }

    /**
     * @param ReflectionClass<object> $owner
     * @return array{key: string, param: string, type: ?ReflectionType, optional: bool, default: mixed, field: ?Field, listOf: ?string}
     */
    private static function describe(ReflectionParameter|ReflectionProperty $p, ?string $docItem, ReflectionClass $owner): array
    {
        $field = null;
        $listOf = null;
        $attrs = $p->getAttributes();
        // A promoted parameter's attributes may sit on the property.
        if ($p instanceof ReflectionParameter && $p->isPromoted() && $owner->hasProperty($p->getName())) {
            $attrs = array_merge($attrs, $owner->getProperty($p->getName())->getAttributes());
        }
        foreach ($attrs as $attr) {
            $instance = match ($attr->getName()) {
                Field::class, ListOf::class => $attr->newInstance(),
                default => null,
            };
            if ($instance instanceof Field) {
                $field = $instance;
            } elseif ($instance instanceof ListOf) {
                $listOf = $instance->type;
            }
        }
        if ($p instanceof ReflectionParameter) {
            $optional = $p->isDefaultValueAvailable();
            $default = $optional ? $p->getDefaultValue() : null;
        } else {
            $optional = $p->hasDefaultValue() && ($p->getDefaultValue() !== null || $p->getType()?->allowsNull());
            $default = $p->hasDefaultValue() ? $p->getDefaultValue() : null;
        }
        if ($listOf === null && $docItem !== null) {
            $listOf = self::resolveDocType($docItem, $owner);
        }
        return [
            'key' => $field !== null && $field->name !== null ? $field->name : $p->getName(),
            'param' => $p->getName(),
            'type' => $p->getType(),
            'optional' => $optional,
            'default' => $default,
            'field' => $field,
            'listOf' => $listOf,
        ];
    }

    /**
     * Item types from `@param list<Route> $routes` or `@var Route[]`.
     *
     * @return array<string, string> parameter name ('' for @var) => item type
     */
    private static function docListTypes(string $doc, string $tag): array
    {
        $out = [];
        $re = '/' . preg_quote($tag, '/') . '\s+(?:(?:list|array|non-empty-list|non-empty-array)<(?:[^,>]+,\s*)?([\\\\\w]+)>|([\\\\\w]+)\[\])(?:\|null)?(?:\s+\$(\w+))?/';
        if (preg_match_all($re, $doc, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $type = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
                $out[$match[3] ?? ''] = $type;
            }
        }
        return $out;
    }

    /** @param ReflectionClass<object> $owner */
    private static function resolveDocType(string $type, ReflectionClass $owner): ?string
    {
        $scalar = ['string' => 'string', 'int' => 'int', 'integer' => 'int', 'float' => 'float', 'bool' => 'bool', 'boolean' => 'bool'];
        if (isset($scalar[strtolower($type)])) {
            return $scalar[strtolower($type)];
        }
        $type = ltrim($type, '\\');
        foreach ([$type, $owner->getNamespaceName() . '\\' . $type] as $candidate) {
            if (class_exists($candidate) || enum_exists($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * The non-null named types of a type, and whether it allows null.
     *
     * @return array{list<ReflectionNamedType>, bool}
     */
    public static function split(?ReflectionType $type): array
    {
        if ($type === null) {
            return [[], true];
        }
        $named = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
        $out = [];
        foreach ($named as $t) {
            if ($t instanceof ReflectionNamedType && $t->getName() !== 'null') {
                $out[] = $t;
            }
        }
        return [$out, $type->allowsNull()];
    }
}
