<?php

namespace App\Modules\AnonymousExam\Console\Commands;

use App\Modules\AnonymousExam\Bale\BaleApi;
use App\Modules\AnonymousExam\Bale\BaleApiException;
use App\Modules\AnonymousExam\Services\BotStatusService;
use Illuminate\Console\Command;

/**
 * Verifies real Bale API behaviour (getMe, getChat, getChatMember, getChatAdministrators, ...)
 * and optionally records the outcome in docs/BALE-API-VERIFIED.md. Ids are masked in the doc.
 */
class BotCheckCommand extends Command
{
    protected $signature = 'exam:bot-check
        {--chat= : group chat id to probe}
        {--user= : user id to probe with getChatMember}
        {--write-doc : append the results to docs/BALE-API-VERIFIED.md}';

    protected $description = 'Check the Bale bot token and probe API methods used by the anonymous exam module';

    public function handle(BaleApi $bale, BotStatusService $status): int
    {
        if (!$bale->isConfigured()) {
            $this->error('BALE_BOT_TOKEN is empty in .env');

            return self::FAILURE;
        }

        $results = [];
        $me = $this->probe('getMe', fn () => $bale->getMe(), $results);
        $status->check();
        if (!$me) {
            $this->error('getMe failed - token invalid or Bale unreachable.');

            return self::FAILURE;
        }
        $this->info('@' . ($me['username'] ?? '?') . ' OK');

        $this->probe('getWebhookInfo', fn () => $bale->getWebhookInfo(), $results);

        $chat = $this->option('chat');
        $user = $this->option('user');
        if ($chat) {
            $this->probe('getChat', fn () => $bale->getChat($chat), $results);
            $this->probe('getChatAdministrators', fn () => $bale->getChatAdministrators($chat), $results);
            $this->probe('getChatMembersCount', fn () => $bale->getChatMembersCount($chat), $results);
            if ($user) {
                $this->probe('getChatMember', fn () => $bale->getChatMember($chat, $user), $results);
                $this->probe('getChatMember (non-member id 1)', fn () => $bale->getChatMember($chat, 1), $results);
            }
        }

        $this->table(['method', 'ok', 'detail'], array_map(fn ($r) => [$r['method'], $r['ok'] ? 'yes' : 'NO', $r['detail']], $results));

        if ($this->option('write-doc')) {
            $this->writeDoc($results);
            $this->info('Results appended to docs/BALE-API-VERIFIED.md');
        }

        return self::SUCCESS;
    }

    private function probe(string $method, callable $call, array &$results): mixed
    {
        try {
            $value = $call();
            $results[] = ['method' => $method, 'ok' => true, 'detail' => $this->describe($value)];

            return $value;
        } catch (BaleApiException $e) {
            $results[] = ['method' => $method, 'ok' => false, 'detail' => ($e->errorCode ?? '-') . ': ' . mb_substr($e->getMessage(), 0, 120)];

            return null;
        }
    }

    /** Short, id-masked description of a result */
    private function describe(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return 'list(' . count($value) . ')' . (isset($value[0]['status']) ? ' statuses=' . implode(',', array_unique(array_column($value, 'status'))) : '');
            }
            $keys = array_slice(array_keys($value), 0, 10);
            $extra = isset($value['status']) ? ' status=' . $value['status'] : '';
            $extra .= isset($value['type']) ? ' type=' . $value['type'] : '';

            return 'keys=' . implode(',', $keys) . $extra;
        }

        return is_scalar($value) ? (string) $value : gettype($value);
    }

    private function writeDoc(array $results): void
    {
        $path = base_path('docs/BALE-API-VERIFIED.md');
        $lines = ["\n### Run " . now()->toDateTimeString() . " UTC\n", '| method | ok | detail |', '|---|---|---|'];
        foreach ($results as $r) {
            $detail = preg_replace('/\d{5,}/', '…', $r['detail']); // mask ids
            $lines[] = '| ' . $r['method'] . ' | ' . ($r['ok'] ? '✅' : '❌') . ' | ' . str_replace('|', '/', $detail) . ' |';
        }
        file_put_contents($path, implode("\n", $lines) . "\n", FILE_APPEND);
    }
}
