<?php

// docuconf for Laravel. Variables are declared where they are used, in
// config/*.php, with Docuconf\Laravel\Env instead of env().

return [
    // The service name in the contract: a DNS label such as "orders".
    // Defaults to the slug of app.name (APP_NAME).
    'name' => env('DOCUCONF_SERVICE', null),

    // The app version or git SHA written into the contract, if any.
    'app_version' => null,

    // Also declare the variables Laravel reads itself (APP_KEY, APP_ENV,
    // APP_DEBUG, APP_URL, LOG_LEVEL, DB_URL, DB_PASSWORD): ['laravel'].
    'presets' => [],

    // Validate the environment when the app boots: on every HTTP request
    // boot, and for every artisan command except skip_commands. Turning
    // this off leaves only `php artisan docuconf:check`.
    'validate' => true,

    // Artisan commands that do not run the app, and so skip the check (a
    // trailing * matches any suffix). They print a warning for each
    // invalid value instead. Every other command (serve, queue:work,
    // migrate, tinker, your own commands) refuses to start on a bad
    // configuration.
    'skip_commands' => [
        'list', 'help', 'completion', '_complete', 'about', 'env', 'inspire',
        'package:discover', 'vendor:publish', 'key:generate', 'storage:link', 'clear-compiled',
        'config:*', 'cache:*', 'optimize*', 'route:*', 'view:*', 'event:*',
        'make:*', 'stub:publish', 'install:*', 'lang:publish', 'docuconf:*',
    ],
];
