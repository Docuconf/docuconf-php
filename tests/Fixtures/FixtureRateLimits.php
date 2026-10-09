<?php

declare(strict_types=1);

namespace Docuconf\Tests\Fixtures;

use Docuconf\Schema\Field;

/** RATE_LIMITS in the shared export fixture (docuconf-go conformance/export). */
final class FixtureRateLimits
{
    public function __construct(
        #[Field(minimum: 1)] public readonly int $perMinute,
        #[Field(minimum: 0, nullable: false)] public readonly ?int $burst = null,
    ) {
    }
}
