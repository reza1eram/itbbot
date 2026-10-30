<?php

namespace App\Modules\AnonymousExam\Services;

use App\Modules\AnonymousExam\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/** Key/value settings editable from the panel (ax_settings), with .env/config defaults. */
class SettingsService
{
    private const CACHE_KEY = 'ax:settings';

    public const EDITABLE = ['privacy_text', 'token_ttl_minutes', 'default_min_responses', 'default_reminders'];

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->all()[$key] ?? $default;
    }

    public function put(string $key, ?string $value): void
    {
        Setting::putValue($key, $value);
        Cache::forget(self::CACHE_KEY);
    }

    public function tokenTtlMinutes(): int
    {
        $v = (int) $this->get('token_ttl_minutes', (string) config('anonymous_exam.anon.token_ttl_minutes', 20));

        return max(1, min(1440, $v));
    }

    public function defaultMinResponses(): int
    {
        return max(1, (int) $this->get('default_min_responses', (string) config('anonymous_exam.anon.min_responses_to_show', 5)));
    }

    /** @return int[] minutes before the end */
    public function defaultReminders(): array
    {
        $raw = $this->get('default_reminders');
        if ($raw === null) {
            return config('anonymous_exam.default_reminders', [60, 10]);
        }

        return self::parseReminders($raw);
    }

    public function privacyText(): string
    {
        return (string) ($this->get('privacy_text') ?: trans('ax::bot.privacy', [], 'fa'));
    }

    /** "60, 10" -> [60, 10] */
    public static function parseReminders(string|array|null $raw): array
    {
        $items = is_array($raw) ? $raw : preg_split('/[\s,،]+/u', (string) $raw);
        $out = [];
        foreach ($items as $item) {
            $n = (int) $item;
            if ($n > 0 && $n <= 10080) {
                $out[] = $n;
            }
        }
        $out = array_values(array_unique($out));
        rsort($out);

        return $out;
    }

    /** @return array<string,string|null> */
    private function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, function () {
            if (!Schema::hasTable('ax_settings')) {
                return [];
            }

            return Setting::query()->pluck('value', 'key')->all();
        });
    }
}
