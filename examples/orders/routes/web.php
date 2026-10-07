<?php

use Docuconf\Values;
use Illuminate\Support\Facades\Route;

Route::get('/healthz', fn () => response('ok', 200, ['Content-Type' => 'text/plain']));

// The typed configuration, with the secret shown as "***".
Route::get('/config', fn (Values $config) => $config->redacted());
