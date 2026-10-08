<?php

use Docuconf\Laravel\Env;

// Env:: is env() with a type, rules and a description. Each call returns the
// typed value, and the app refuses to boot if any of them is wrong.

return [
    'port' => Env::int('PORT', 'HTTP listen port', default: 8080, min: 1, max: 65535),

    // ORDERS_, not LOG_LEVEL: Laravel's own config/logging.php reads LOG_LEVEL.
    'log_level' => Env::enum('ORDERS_LOG_LEVEL', 'Minimum level the orders code logs', ['debug', 'info', 'warn', 'error'], default: 'info'),

    // A secret: never printed, and the platform must supply it from a Secret.
    'database_url' => Env::url('DATABASE_URL', 'Orders database connection string', required: true, schemes: ['postgres', 'postgresql'], secret: true),

    'allowed_origins' => Env::list('ALLOWED_ORIGINS', 'Origins allowed to call the API (CORS)', default: ['http://localhost:3000'], minItems: 1),

    // A Docuconf\Duration; written "30s", "1m30s" in the env. The PHPDoc
    // comment documents it: its first paragraph is the description, and the
    // rest is exported as details, for `docuconf docs`.

    /**
     * Timeout for each request.
     *
     * Raise it when clients upload large order batches. Keep it below the
     * load balancer's idle timeout, or the client sees a reset rather than
     * a `504`.
     */
    'request_timeout' => Env::duration('REQUEST_TIMEOUT', default: '30s', min: '1s', max: '5m'),

    'worker_count' => Env::int('WORKER_COUNT', 'Number of background workers', default: 4, min: 1, max: 64),
];
