<?php

declare(strict_types=1);

namespace Docuconf;

/**
 * Names the variable a constructor parameter binds to in `Values::bind()`,
 * when it is not the parameter name in SCREAMING_SNAKE_CASE.
 *
 * ```php
 * final class OrdersConfig
 * {
 *     public function __construct(
 *         public readonly int $port,                                   // PORT
 *         #[FromEnv('DATABASE_URL')] public readonly Secret $database, // DATABASE_URL
 *     ) {}
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final class FromEnv
{
    public function __construct(public readonly string $name)
    {
    }
}
