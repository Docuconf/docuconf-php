<?php

use App\Webhooks;
use Docuconf\Values;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/healthz', fn () => response('ok', 200, ['Content-Type' => 'text/plain']));

// The typed configuration, with the secrets shown as "***".
Route::get('/config', fn (Values $config) => $config->redacted());

// Payment webhooks, signed with any key in the WEBHOOK_KEYS key set.
Route::post('/webhooks/payments', function (Request $request, Values $config) {
    $body = $request->getContent();
    if (strlen($body) > Webhooks::MAX_BODY) {
        return response('body too large', 413);
    }
    $ok = Webhooks::verify($config->keySet('WEBHOOK_KEYS'), $body, $request->header('X-Signature'));
    return $ok ? response()->noContent() : response('bad signature', 401);
});
