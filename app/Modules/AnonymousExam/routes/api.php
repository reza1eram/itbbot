<?php

use App\Modules\AnonymousExam\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Bale webhook (BALE_UPDATE_MODE=webhook). The unguessable path segment is the only secret Bale supports.
Route::post('/bale/exam/{secret}', [WebhookController::class, 'handle'])
    ->where('secret', '[A-Za-z0-9_-]{16,128}')
    ->middleware('throttle:ax-webhook')
    ->name('ax.webhook');
