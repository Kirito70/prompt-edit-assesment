<?php

use App\Http\Controllers\Webhooks\PixelForgeWebhookController;
use Illuminate\Support\Facades\Route;

// Stateless: no session, cookies or CSRF. Authenticity comes from the signature.
Route::post('webhooks/pixelforge', PixelForgeWebhookController::class)->name('webhooks.pixelforge');
