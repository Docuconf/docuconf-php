<?php

declare(strict_types=1);

use Docuconf\Env;

// Inputs documented with PHPDoc comments, for DocsTest.

$env = Env::declare('documented');

/**
 * HTTP listen port.
 *
 * Behind the mesh, keep the default. The sidecar forwards {@link Ingress}
 * traffic here; see `PORT` in the chart.
 *
 * @since 1.2.0
 */
$env->ifPresent('PORT')->isInteger()->default(8080)->between(1, 65535);

/**
 * Cloud region for object storage.
 *
 * Change it together with the bucket:
 *
 * - `eu-west-1` for Europe
 * - `us-east-1` for the US
 *
 * ```sh
 * REGION=us-east-1 php artisan serve
 * ```
 *
 * @see https://example.com/regions
 */
$env
    ->ifPresent('REGION')
    ->default('eu-west-1');

/** Ignored: describe() and details() win. */
$env->ifPresent('WORKERS')->isInteger()->default(4)
    ->describe('Worker processes')->details('One per *core*.');

$env->ifPresent('PLAIN')->default('x')->describe('No details at all');

/**
 * Licence key file.
 *
 * Issued per customer; rotate it yearly.
 */
$env->text('license', '/etc/app/license/license.key');

return $env;
