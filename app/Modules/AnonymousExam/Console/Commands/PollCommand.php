<?php

namespace App\Modules\AnonymousExam\Console\Commands;

use App\Modules\AnonymousExam\Bale\BaleApi;
use App\Modules\AnonymousExam\Bale\BaleApiException;
use App\Modules\AnonymousExam\Models\Setting;
use App\Modules\AnonymousExam\Services\BotUpdateHandler;
use App\Modules\AnonymousExam\Services\OutboxService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Long-polling loop (BALE_UPDATE_MODE=polling). Polling and webhook cannot coexist,
 * so the webhook is deleted first. The offset is persisted in ax_settings.
 */
class PollCommand extends Command
{
    protected $signature = 'exam:poll {--once : process one batch and exit}';

    protected $description = 'Receive Bale updates with getUpdates (local development without a public URL)';

    private bool $running = true;

    public function handle(BaleApi $bale, BotUpdateHandler $handler, OutboxService $outbox): int
    {
        if (!$bale->isConfigured()) {
            $this->error('BALE_BOT_TOKEN is empty in .env');

            return self::FAILURE;
        }

        if (extension_loaded('pcntl')) {
            $this->trap([SIGINT, SIGTERM], fn () => $this->running = false);
        }

        try {
            $bale->deleteWebhook();
        } catch (BaleApiException $e) {
            $this->warn('deleteWebhook failed: ' . $e->getMessage());
        }

        $offset = (int) Setting::getValue('poll_offset', '0');
        $timeout = (int) config('anonymous_exam.bale.poll_timeout', 25);
        $this->info('Polling started (offset ' . $offset . ').');

        while ($this->running) {
            try {
                $updates = $bale->getUpdates($offset, 100, $this->option('once') ? 0 : $timeout);
                foreach ($updates as $update) {
                    $handler->handle($update);
                    $offset = max($offset, (int) ($update['update_id'] ?? 0) + 1);
                    Setting::putValue('poll_offset', (string) $offset);
                }
                $outbox->dispatchDue(20);

                if (!$updates && !$this->option('once')) {
                    sleep(1); // in case Bale ignores the long-poll timeout
                }
            } catch (Throwable $e) {
                $this->warn('poll error: ' . class_basename($e) . ' ' . mb_substr($e->getMessage(), 0, 150));
                sleep(5);
            }

            if ($this->option('once')) {
                break;
            }
        }

        $this->info('Polling stopped.');

        return self::SUCCESS;
    }
}
