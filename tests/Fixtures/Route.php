<?php

declare(strict_types=1);

namespace Docuconf\Tests\Fixtures;

use Docuconf\Duration;
use Docuconf\Schema\Field;

final class Route
{
    public function __construct(
        #[Field(pattern: '^/')] public readonly string $match,
        #[Field(pattern: '^https?://')] public readonly string $upstream,
        public readonly ?Duration $timeout = null,
    ) {
    }
}
