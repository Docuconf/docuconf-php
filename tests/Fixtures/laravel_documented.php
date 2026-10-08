<?php

declare(strict_types=1);

use Docuconf\Laravel\Env;

// What a documented config/orders.php would hold, for DocsTest.

return [
    /**
     * HTTP listen port.
     *
     * Behind the mesh, keep the default.
     */
    'port' => Env::int('DOC_PORT', '', default: 8080, min: 1, max: 65535),

    /**
     * Raise it for batch clients.
     */
    'timeout' => Env::duration('DOC_TIMEOUT', 'Request timeout', default: '30s'),

    'workers' => Env::int('DOC_WORKERS', 'Worker processes', default: 4, details: 'One per core.'),
];
