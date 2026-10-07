<?php

declare(strict_types=1);

namespace Docuconf\Tests\Fixtures;

use Docuconf\Schema\Field;
use Docuconf\Schema\ListOf;

/** The routing table the sample gateway binds routes.yaml to. */
final class Routes
{
    /** @param list<Route> $routes */
    public function __construct(
        #[ListOf(Route::class), Field(minItems: 1)] public readonly array $routes,
    ) {
    }
}
