<?php

namespace App\Modules\AnonymousExam\Console\Commands;

use App\Modules\AnonymousExam\Bale\BaleApi;
use App\Modules\AnonymousExam\Services\BotStatusService;
use Illuminate\Console\Command;
use Throwable;

class WebhookCommand extends Command
{
    protected $signature = 'exam:webhook {action : set|info|delete}';

    protected $description = 'Manage the Bale webhook of the anonymous exam bot (BALE_UPDATE_MODE=webhook)';

    public function handle(BaleApi $bale, BotStatusService $status): int
    {
        try {
            switch ($this->argument('action')) {
                case 'set':
                    $status->setWebhook();
                    $this->info('Webhook set (secret path hidden). Run "exam:webhook info" to verify.');
                    break;
                case 'info':
                    foreach ($status->webhookInfo() as $k => $v) {
                        $this->line(str_pad((string) $k, 24) . ' ' . (is_scalar($v) ? var_export($v, true) : json_encode($v)));
                    }
                    break;
                case 'delete':
                    $bale->deleteWebhook();
                    $this->info('Webhook deleted.');
                    break;
                default:
                    $this->error('Unknown action. Use set, info or delete.');

                    return self::INVALID;
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
