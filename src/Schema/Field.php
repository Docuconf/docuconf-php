<?php

declare(strict_types=1);

namespace Docuconf\Schema;

use Attribute;

/**
 * JSON Schema keywords for one property of a config type: a constructor
 * parameter or a public property. Everything is optional.
 *
 * ```php
 * public function __construct(
 *     #[Field(pattern: '^/', description: 'Path prefix')] public readonly string $match,
 *     #[Field(minimum: 1)] public readonly int $perMinute,
 * ) {}
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Field
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly int|float|null $minimum = null,
        public readonly int|float|null $maximum = null,
        public readonly ?int $minLength = null,
        public readonly ?int $maxLength = null,
        public readonly ?string $pattern = null,
        public readonly ?int $minItems = null,
        public readonly ?int $maxItems = null,
        /**
         * False for a nullable property with a null default that the file
         * may leave out but never set to null: its schema is the non-null
         * type, with no default, as an omitted optional key in Go or
         * TypeScript (`public readonly ?int $burst = null`).
         */
        public readonly bool $nullable = true,
    ) {
    }
}
