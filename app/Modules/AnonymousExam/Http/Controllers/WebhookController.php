<?php

namespace App\Modules\AnonymousExam\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AnonymousExam\Services\BotUpdateHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /api/bale/exam/{secret}. Unlike the repo's other webhooks the token is NOT part of the URL;
 * the bot is identified by an unguessable secret path segment (BALE_WEBHOOK_SECRET).
 * The request body is never logged.
 */
class WebhookController extends Controller
{
    public function handle(Request $request, string $secret, BotUpdateHandler $handler): JsonResponse
    {
        $expected = (string) config('anonymous_exam.bale.webhook_secret_path');
        if ($expected === '' || !hash_equals($expected, $secret)) {
            abort(404);
        }

        $update = $request->json()->all();
        if (!is_array($update) || $update === []) {
            return response()->json(['ok' => true]);
        }

        try {
            $handler->handle($update);
        } catch (Throwable $e) {
            Log::error('ax webhook failed', ['error' => class_basename($e)]);
        }

        // Always 200: a failing update must not be redelivered forever
        return response()->json(['ok' => true]);
    }
}
