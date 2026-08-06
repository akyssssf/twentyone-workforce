<?php

use App\Http\Controllers\FingerspotWebhookController;
use App\Http\Middleware\VerifyFingerspotWebhook;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook Fingerspot
|--------------------------------------------------------------------------
|
| URL yang didaftarkan di panel developer.fingerspot.io:
|
|   POST {APP_URL}/api/fingerspot/webhook/{FINGERSPOT_WEBHOOK_SECRET}
|
| Ada di grup api, jadi bebas CSRF dan bebas session, yang memang dibutuhkan
| karena mesin cuma bisa POST JSON polos tanpa cookie.
|
| Segmen secret dibatasi 32-128 karakter heksadesimal supaya URL asal-asalan
| berhenti di router dan tidak sempat menyentuh middleware.
|
*/

Route::post('fingerspot/webhook/{secret}', FingerspotWebhookController::class)
    ->middleware(VerifyFingerspotWebhook::class)
    ->where('secret', '[A-Za-z0-9]{32,128}')
    ->name('fingerspot.webhook');
