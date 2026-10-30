<?php

namespace App\Modules\AnonymousExam\Bale;

use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Standalone Bale client on top of Laravel's Http client.
 *
 * - retries with exponential backoff on network errors / 5xx / 429 (honours retry_after)
 * - never logs or throws anything containing the bot token (it lives in the URL path)
 */
class BaleClientImpl implements BaleApi
{
    public function __construct(
        private readonly ?string $token,
        private readonly string $baseUrl = 'https://tapi.bale.ai',
        private readonly int $timeout = 15,
        private readonly int $retries = 3,
    ) {
    }

    public function isConfigured(): bool
    {
        return is_string($this->token) && $this->token !== '';
    }

    public function call(string $method, array $params = [], ?int $timeout = null): mixed
    {
        if (!$this->isConfigured()) {
            throw new BaleApiException('BALE_BOT_TOKEN is not configured', $method);
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $response = Http::asJson()
                    ->acceptJson()
                    ->timeout(($timeout ?? $this->timeout) + 5)
                    ->withOptions(['verify' => self::caBundle()])
                    ->post($this->url($method), $params);

                return $this->parse($method, $response);
            } catch (BaleApiException $e) {
                $retryable = $e->isRateLimited() || ($e->errorCode !== null && $e->errorCode >= 500);
                if (!$retryable || $attempt >= $this->retries) {
                    $this->logFailure($method, $e->errorCode, $e->getMessage());
                    throw $e;
                }
                $this->backoff($attempt, $e->retryAfter);
            } catch (Throwable $e) {
                // Network level failure. The original message may contain the URL (and so the token).
                $safe = $this->sanitize($e->getMessage());
                if ($attempt >= $this->retries) {
                    $this->logFailure($method, null, $safe);
                    throw new BaleApiException($safe, $method);
                }
                $this->backoff($attempt, null);
            }
        }
    }

    public function getMe(): array
    {
        return (array) $this->call('getMe');
    }

    public function getChat(int|string $chatId): array
    {
        return (array) $this->call('getChat', ['chat_id' => $chatId]);
    }

    public function getChatMember(int|string $chatId, int|string $userId): array
    {
        return (array) $this->call('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
    }

    public function getChatAdministrators(int|string $chatId): array
    {
        return (array) $this->call('getChatAdministrators', ['chat_id' => $chatId]);
    }

    public function getChatMembersCount(int|string $chatId): int
    {
        return (int) $this->call('getChatMembersCount', ['chat_id' => $chatId]);
    }

    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null): array
    {
        $params = ['chat_id' => $chatId, 'text' => $text];
        if ($replyMarkup !== null) {
            $params['reply_markup'] = $replyMarkup;
        }

        return (array) $this->call('sendMessage', $params);
    }

    public function deleteMessage(int|string $chatId, int|string $messageId): bool
    {
        return (bool) $this->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false, ?string $url = null): bool
    {
        $params = array_filter([
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $showAlert ?: null,
            'url' => $url,
        ], fn ($v) => $v !== null);

        return (bool) $this->call('answerCallbackQuery', $params);
    }

    public function getUpdates(int $offset = 0, int $limit = 100, int $timeout = 0): array
    {
        $params = ['offset' => $offset, 'limit' => max(1, min(100, $limit))];
        if ($timeout > 0) {
            $params['timeout'] = $timeout;
        }

        return (array) $this->call('getUpdates', $params, $timeout + $this->timeout);
    }

    public function setWebhook(string $url): bool
    {
        return (bool) $this->call('setWebhook', ['url' => $url]);
    }

    public function deleteWebhook(): bool
    {
        return (bool) $this->call('deleteWebhook');
    }

    public function getWebhookInfo(): array
    {
        return (array) $this->call('getWebhookInfo');
    }

    /**
     * TLS verification stays ON. Windows PHP builds often have no CA list configured (curl.cainfo empty),
     * which makes every HTTPS call fail; CaBundle returns the system CA file, or its bundled Mozilla list.
     */
    private static function caBundle(): string|bool
    {
        static $path = null;

        return $path ??= (class_exists(CaBundle::class) ? CaBundle::getSystemCaRootBundlePath() : true);
    }

    private function url(string $method): string
    {
        return rtrim($this->baseUrl, '/') . '/bot' . $this->token . '/' . $method;
    }

    private function parse(string $method, Response $response): mixed
    {
        $json = $response->json();

        if (is_array($json) && ($json['ok'] ?? false) === true) {
            return $json['result'] ?? null;
        }

        $code = is_array($json) ? (int) ($json['error_code'] ?? $response->status()) : $response->status();
        $description = is_array($json) ? (string) ($json['description'] ?? 'unknown error') : 'invalid response';
        $retryAfter = is_array($json) ? ($json['parameters']['retry_after'] ?? null) : null;

        throw new BaleApiException($this->sanitize($description), $method, $code, $retryAfter !== null ? (int) $retryAfter : null);
    }

    private function backoff(int $attempt, ?int $retryAfter): void
    {
        $seconds = $retryAfter !== null ? min($retryAfter, 30) : min(2 ** ($attempt - 1), 8);
        if (!app()->runningUnitTests()) {
            usleep((int) ($seconds * 1_000_000));
        }
    }

    private function sanitize(string $message): string
    {
        if ($this->isConfigured()) {
            $message = str_replace($this->token, '***', $message);
        }

        // Defensive: strip anything that looks like a bot path segment
        return (string) preg_replace('#/bot[^/\s]+/#', '/bot***/', $message);
    }

    private function logFailure(string $method, ?int $code, string $message): void
    {
        Log::warning('Bale API call failed', ['method' => $method, 'error_code' => $code, 'error' => mb_substr($message, 0, 200)]);
    }
}
