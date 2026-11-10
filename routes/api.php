<?php

use App\Http\Controllers\Api\DisplayApiController;
use App\Http\Controllers\Api\FiscalAgentController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Middleware\AuthenticateDisplay;
use App\Http\Middleware\AuthenticateFiscalAgent;
use Illuminate\Support\Facades\Route;

/*
| Rute fără sesiune și fără CSRF: autentificarea se face prin semnătura furnizorului sau HMAC.
| Prefix automat: /api
*/
Route::post('/webhooks/{provider}', [WebhookController::class, 'handle'])
    ->where('provider', '[a-z0-9_-]+')
    ->middleware('throttle:webhooks')
    ->name('webhooks.handle');

Route::prefix('fiscal-agent')->middleware(['throttle:fiscal-agent', AuthenticateFiscalAgent::class])->group(function () {
    Route::get('/commands', [FiscalAgentController::class, 'commands'])->name('fiscal-agent.commands');
    Route::post('/commands/{commandId}/result', [FiscalAgentController::class, 'result'])->name('fiscal-agent.result');
    Route::post('/heartbeat', [FiscalAgentController::class, 'heartbeat'])->name('fiscal-agent.heartbeat');
});

/*
| Playerul ecranelor din arenă: activare cu cod de unică folosință, apoi acces read-only cu credențiala dispozitivului.
*/
Route::prefix('display/{slug}')->where(['slug' => '[a-z0-9\-]{2,60}'])->group(function () {
    Route::post('/activate', [DisplayApiController::class, 'activate'])->middleware('throttle:display-activate')->name('display.activate');
    Route::middleware(['throttle:display', AuthenticateDisplay::class])->group(function () {
        Route::get('/state', [DisplayApiController::class, 'state'])->name('display.state');
        Route::post('/heartbeat', [DisplayApiController::class, 'heartbeat'])->name('display.heartbeat');
    });
});
