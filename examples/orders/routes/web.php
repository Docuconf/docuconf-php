<?php

use App\Webhooks;
use Docuconf\Values;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/healthz', fn () => response('ok', 200, ['Content-Type' => 'text/plain']));

// The typed configuration, with the secrets shown as "***".
Route::get('/config', fn (Values $config) => $config->redacted());

// Payment webhooks, signed with any key in WEBHOOK_KEYS (see config/orders.php
// for how to rotate it).
Route::post('/webhooks/payments', function (Request $request) {
    $body = $request->getContent();
    if (strlen($body) > Webhooks::MAX_BODY) {
        return response('body too large', 413);
    }
    $ok = Webhooks::verify(config('orders.webhook_keys'), $body, $request->header('X-Signature'));
    return $ok ? response()->noContent() : response('bad signature', 401);
});
