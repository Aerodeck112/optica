<?php

use App\Http\Controllers\Api\FiscalAgentController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

/*
| Rute fără sesiune și fără CSRF: autentificarea se face prin semnătura furnizorului sau HMAC.
| Prefix automat: /api
*/
Route::post('/webhooks/{provider}', [WebhookController::class, 'handle'])
    ->where('provider', '[a-z0-9_-]+')
    ->middleware('throttle:webhooks')
    ->name('webhooks.handle');

Route::prefix('fiscal-agent')->middleware('throttle:fiscal-agent')->group(function () {
    Route::get('/commands', [FiscalAgentController::class, 'commands'])->name('fiscal-agent.commands');
    Route::post('/commands/{commandId}/result', [FiscalAgentController::class, 'result'])->name('fiscal-agent.result');
    Route::post('/heartbeat', [FiscalAgentController::class, 'heartbeat'])->name('fiscal-agent.heartbeat');
});
