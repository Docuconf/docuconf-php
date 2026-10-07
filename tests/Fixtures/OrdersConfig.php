<?php

declare(strict_types=1);

namespace Docuconf\Tests\Fixtures;

use Docuconf\Duration;
use Docuconf\FromEnv;
use Docuconf\Secret;

final class OrdersConfig
{
    /** @param list<string> $origins */
    public function __construct(
        public readonly int $port,
        public readonly Secret $databaseUrl,
        public readonly Duration $requestTimeout,
        #[FromEnv('REQUEST_TIMEOUT')] public readonly \DateInterval $timeoutInterval,
        #[FromEnv('ALLOWED_ORIGINS')] public readonly array $origins,
        public readonly ?string $region = null,
    ) {
    }
}
