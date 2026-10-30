<?php

namespace App\Modules\AnonymousExam\Services;

use App\Modules\AnonymousExam\Models\MessageTemplate;
use Illuminate\Support\Facades\Cache;

/**
 * Bot texts: defaults come from lang/fa/bot.php (namespace "ax"), admins may override them in ax_message_templates.
 */
class TemplateService
{
    private const CACHE_KEY = 'ax:templates';

    /** Placeholders that each template may use (shown in the panel) */
    public const VARIABLES = [
        'exam_not_started' => [':starts_at'],
        'not_member' => [':group'],
        'access_link' => [':title', ':minutes'],
        'announce' => [':title', ':description', ':ends_at'],
        'reminder' => [':title', ':minutes', ':count'],
        'closed' => [':title', ':count'],
        'closed_score' => [':avg', ':max'],
        'register_ok' => [':title'],
        'exams_item' => [':title', ':ends_at', ':code'],
        'admin_myexams_item' => [':title', ':status', ':code', ':count'],
        'admin_close_ok' => [':title'],
        'admin_results' => [':title', ':count', ':details'],
    ];

    public function render(string $key, array $vars = []): string
    {
        $body = $this->overrides()[$key] ?? trans('ax::bot.' . $key, [], 'fa');

        $replace = [];
        foreach ($vars as $name => $value) {
            $replace[':' . $name] = (string) $value;
        }
        // Longest placeholders first so ":count" never eats ":counter"
        uksort($replace, fn ($a, $b) => strlen($b) <=> strlen($a));

        return strtr($body, $replace);
    }

    /** @return array<string,string> default texts keyed by template key */
    public function defaults(): array
    {
        return (array) trans('ax::bot', [], 'fa');
    }

    /** @return array<string,array{default:string,override:?string,variables:array}> */
    public function all(): array
    {
        $overrides = $this->overrides();
        $out = [];
        foreach ($this->defaults() as $key => $default) {
            $out[$key] = [
                'default' => $default,
                'override' => $overrides[$key] ?? null,
                'variables' => self::VARIABLES[$key] ?? [],
            ];
        }

        return $out;
    }

    public function save(string $key, ?string $body): void
    {
        if ($body === null || trim($body) === '' || $body === ($this->defaults()[$key] ?? null)) {
            MessageTemplate::query()->where('key', $key)->delete();
        } else {
            MessageTemplate::query()->updateOrCreate(['key' => $key], ['body' => $body]);
        }
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string,string> */
    private function overrides(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, fn () => MessageTemplate::query()->pluck('body', 'key')->all());
    }
}
