<?php

declare(strict_types=1);

namespace Docuconf\Tests\Fixtures;

/** Binds DATABASE_URL (a string) to an int, which fails. */
final class WrongTypes
{
    public function __construct(public readonly int $databaseUrl)
    {
    }
}
