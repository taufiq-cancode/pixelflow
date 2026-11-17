<?php

use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook routes
|--------------------------------------------------------------------------
|
| Loaded outside the "web" middleware group (see bootstrap/app.php): no
| session, cookies or CSRF token, because Pixelforge has none. Requests are
| authenticated by their HMAC signature instead.
|
*/

Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');
