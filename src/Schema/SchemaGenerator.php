<?php

declare(strict_types=1);

namespace Docuconf\Schema;

use BackedEnum;
use Docuconf\DeclarationError;
use Docuconf\Duration;
use ReflectionEnum;
use ReflectionNamedType;

/**
 * Generates a JSON Schema from the app's own config type (SPEC §4.6:
 * "schemas come from code"), so the platform checks a config file or a
 * `json` variable against the type the app binds it to.
 *
 * Supported: int, float, string, bool, nullable types, unions of those,
 * backed enums, Duration (Go syntax), nested classes (as `$defs`), arrays
 * with an item type from `#[ListOf]` or a `list<T>` docblock, and the
 * keywords of `#[Field]`. Unknown properties are rejected
 * (`additionalProperties: false`), as Hydrator does.
 */
final class SchemaGenerator
{
    /** @var array<string, array<string, mixed>> */
    private array $defs = [];
    /** @var array<string, true> */
    private array $inProgress = [];

    /**
     * @param class-string $class
     * @return array<string, mixed>
     */
    public static function forClass(string $class): array
    {
        if (!class_exists($class)) {
            throw new DeclarationError(["schema class $class does not exist"]);
        }
        $gen = new self();
        $root = $gen->object($class);
        $short = self::shortName($class);
        // The root type is inlined; nested classes go in $defs.
        unset($gen->defs[$short]);
        if ($gen->defs !== []) {
            ksort($gen->defs);
            $root = ['$defs' => $gen->defs] + $root;
        }
        return $root;
    }

    /**
     * @param class-string $class
     * @return array<string, mixed>
     */
    private function object(string $class): array
    {
        $short = self::shortName($class);
        $this->inProgress[$short] = true;
        $properties = [];
        $required = [];
        foreach (ClassShape::properties($class) as $p) {
            $field = $p['field'];
            $nullable = $field === null || $field->nullable;
            $schema = $this->property($p['type'], $p['listOf'], $nullable);
            if ($field !== null) {
                $schema += array_filter([
                    'description' => $field->description,
                    'minimum' => $field->minimum,
                    'maximum' => $field->maximum,
                    'minLength' => $field->minLength,
                    'maxLength' => $field->maxLength,
                    'pattern' => $field->pattern,
                    'minItems' => $field->minItems,
                    'maxItems' => $field->maxItems,
                ], fn ($v) => $v !== null);
            }
            if ($p['optional']) {
                $default = $p['default'];
                if ($default instanceof BackedEnum) {
                    $default = $default->value;
                } elseif ($default instanceof Duration) {
                    $default = $default->toString();
                }
                if (($default === null && $nullable) || is_scalar($default) || is_array($default)) {
                    $schema['default'] = $default;
                }
            } else {
                $required[] = $p['key'];
            }
            $properties[$p['key']] = $schema;
        }
        $out = ['type' => 'object', 'title' => $short];
        $out['properties'] = $properties === [] ? new \stdClass() : $properties;
        if ($required !== []) {
            $out['required'] = $required;
        }
        $out['additionalProperties'] = false;
        unset($this->inProgress[$short]);
        $this->defs[$short] = $out;
        return $out;
    }

    /** @return array<string, mixed> */
    private function property(?\ReflectionType $type, ?string $listOf, bool $allowNull = true): array
    {
        [$named, $nullable] = ClassShape::split($type);
        $nullable = $nullable && $allowNull;
        if ($named === []) {
            return [];
        }
        $schemas = array_map(fn (ReflectionNamedType $t) => $this->named($t->getName(), $listOf), $named);
        if ($nullable && !($type instanceof ReflectionNamedType && $type->getName() === 'mixed')) {
            $schemas[] = ['type' => 'null'];
        }
        return count($schemas) === 1 ? $schemas[0] : ['anyOf' => $schemas];
    }

    /** @return array<string, mixed> */
    private function named(string $name, ?string $listOf): array
    {
        switch ($name) {
            case 'int':
                return ['type' => 'integer'];
            case 'float':
                return ['type' => 'number'];
            case 'string':
                return ['type' => 'string'];
            case 'bool':
                return ['type' => 'boolean'];
            case 'true':
            case 'false':
                return ['const' => $name === 'true'];
            case 'mixed':
                return [];
            case 'array':
            case 'iterable':
                if ($listOf === null) {
                    return ['type' => ['array', 'object']];
                }
                return ['type' => 'array', 'items' => $this->named($listOf, null)];
            case Duration::class:
                return ['type' => 'string', 'pattern' => '^([0-9]+(\\.[0-9]*)?(ns|us|ms|s|m|h))+$'];
        }
        if (enum_exists($name)) {
            $enum = new ReflectionEnum($name);
            if (!$enum->isBacked()) {
                return ['enum' => array_map(fn ($c) => $c->getName(), $enum->getCases())];
            }
            $values = array_map(fn ($c) => $c->getBackingValue(), $enum->getCases());
            return ['type' => (string) $enum->getBackingType() === 'int' ? 'integer' : 'string', 'enum' => $values];
        }
        if (class_exists($name)) {
            $short = self::shortName($name);
            if (!isset($this->defs[$short]) && !isset($this->inProgress[$short])) {
                $this->object($name);
            }
            return ['$ref' => '#/$defs/' . $short];
        }
        return [];
    }

    private static function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
