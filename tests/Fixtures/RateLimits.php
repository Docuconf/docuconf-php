<?php

declare(strict_types=1);

namespace Docuconf\Tests\Fixtures;

use Docuconf\Schema\Field;

/** Default per-client rate limits, bound from the RATE_LIMITS json variable. */
final class RateLimits
{
    public function __construct(
        #[Field(minimum: 1)] public readonly int $perMinute,
        #[Field(minimum: 0)] public readonly int $burst = 0,
    ) {
    }
}
