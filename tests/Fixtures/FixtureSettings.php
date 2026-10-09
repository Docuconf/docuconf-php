<?php

declare(strict_types=1);

namespace Docuconf\Tests\Fixtures;

use Docuconf\Schema\Field;
use Docuconf\Schema\ListOf;

/** The settings type of the shared export fixture's config files. */
final class FixtureSettings
{
    /** @param list<string>|null $tags */
    public function __construct(
        #[Field(minLength: 1)] public readonly string $name,
        #[Field(minimum: 1)] public readonly int $replicas,
        #[ListOf('string'), Field(nullable: false)] public readonly ?array $tags = null,
    ) {
    }
}
