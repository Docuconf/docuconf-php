<?php

declare(strict_types=1);

namespace Docuconf\Schema;

use Attribute;

/**
 * The item type of an array property: a class name, or one of 'string',
 * 'int', 'float', 'bool'. PHP arrays carry no item type, so a config type
 * says it here (a `@param list<Route>` docblock works too).
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class ListOf
{
    /** @param class-string|'string'|'int'|'float'|'bool' $type */
    public function __construct(public readonly string $type)
    {
    }
}
