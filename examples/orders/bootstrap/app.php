<?php

use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    // No 'web' middleware group: the service has no sessions or cookies.
    ->withRouting(using: fn () => require __DIR__.'/../routes/web.php')
    ->create();
