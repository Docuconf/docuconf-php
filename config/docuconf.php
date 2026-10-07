<?php

// docuconf for Laravel. Variables are declared where they are used, in
// config/*.php, with Docuconf\Laravel\Env instead of env().

return [
    // The service name in the contract: a DNS label such as "orders".
    'name' => env('DOCUCONF_SERVICE', null),

    // The app version or git SHA written into the contract, if any.
    'app_version' => null,

    // Validate the environment when the app boots: on every HTTP request
    // boot, and for the console commands below. Turning this off leaves
    // only `php artisan docuconf:check`.
    'validate' => true,

    // Console commands that run the app, and so validate at boot. Other
    // commands (migrations, package:discover, docuconf:export in CI) do not.
    'console_commands' => [
        'serve', 'octane:start', 'octane:frankenphp', 'octane:roadrunner', 'octane:swoole',
        'queue:work', 'queue:listen', 'schedule:work', 'schedule:run', 'horizon', 'reverb:start',
    ],
];
