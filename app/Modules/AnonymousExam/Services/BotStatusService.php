<?php

namespace App\Modules\AnonymousExam\Services;

use App\Modules\AnonymousExam\Bale\BaleApi;
use App\Modules\AnonymousExam\Bale\BaleApiException;
use App\Modules\AnonymousExam\Models\AxBot;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Bot record (ax_bots), getMe health and webhook management */
class BotStatusService
{
    public function __construct(private readonly BaleApi $bale)
    {
    }

    public function record(): AxBot
    {
        return AxBot::query()->firstOrCreate(
            ['username' => (string) config('anonymous_exam.bale.username')],
            ['update_mode' => (string) config('anonymous_exam.bale.update_mode', 'polling')]
        );
    }

    /** @return array{ok:bool,username:?string,error:?string,checked_at:?string,configured:bool} */
    public function check(): array
    {
        $bot = $this->record();
        $bot->update_mode = (string) config('anonymous_exam.bale.update_mode', 'polling');

        if (!$this->bale->isConfigured()) {
            $status = ['ok' => false, 'username' => null, 'error' => 'BALE_BOT_TOKEN تنظیم نشده است.', 'configured' => false];
        } else {
            try {
                $me = $this->bale->getMe();
                $bot->get_me = array_intersect_key($me, array_flip(['id', 'is_bot', 'first_name', 'username', 'can_join_groups', 'can_read_all_group_messages']));
                $status = ['ok' => true, 'username' => $me['username'] ?? null, 'error' => null, 'configured' => true];
            } catch (BaleApiException $e) {
                $status = ['ok' => false, 'username' => null, 'error' => ($e->errorCode ? $e->errorCode . ': ' : '') . $e->getMessage(), 'configured' => true];
            }
        }

        $bot->status = $status['ok'] ? 'ok' : 'error';
        $bot->checked_at = now();
        $bot->save();
        $status['checked_at'] = now()->toIso8601String();
        Cache::put('ax:bot-status', $status, 300);

        return $status;
    }

    public function cachedStatus(): array
    {
        return Cache::get('ax:bot-status') ?? $this->check();
    }

    public function webhookUrl(): string
    {
        $secret = (string) config('anonymous_exam.bale.webhook_secret_path');
        if (strlen($secret) < 16) {
            throw new RuntimeException('BALE_WEBHOOK_SECRET در .env تنظیم نشده یا کوتاه است (حداقل ۱۶ کاراکتر).');
        }
        $base = rtrim((string) (config('anonymous_exam.public_url') ?: config('app.url')), '/');

        return $base . '/api/bale/exam/' . $secret;
    }

    public function setWebhook(): string
    {
        $url = $this->webhookUrl();
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https') {
            throw new RuntimeException('وب‌هوک بله فقط HTTPS می‌پذیرد. EXAM_PUBLIC_URL یا APP_URL را https تنظیم کنید.');
        }
        $port = $parts['port'] ?? 443;
        if (!in_array($port, [443, 88], true)) {
            throw new RuntimeException('وب‌هوک بله فقط روی پورت ۴۴۳ یا ۸۸ کار می‌کند.');
        }
        $this->bale->setWebhook($url);

        return $url;
    }

    /** getWebhookInfo with the secret path masked */
    public function webhookInfo(): array
    {
        $info = $this->bale->getWebhookInfo();
        if (!empty($info['url'])) {
            $info['url'] = preg_replace('#(/api/bale/exam/)[^/?]+#', '$1***', (string) $info['url']);
        }

        return $info;
    }
}
