<?php

namespace App\Modules\AnonymousExam\Console\Commands;

use App\Modules\AnonymousExam\Services\OutboxService;
use Illuminate\Console\Command;

class OutboxCommand extends Command
{
    protected $signature = 'exam:outbox {--limit=50}';

    protected $description = 'Send due group messages from ax_outbox (retries with backoff)';

    public function handle(OutboxService $outbox): int
    {
        $this->line('sent=' . $outbox->dispatchDue((int) $this->option('limit')));

        return self::SUCCESS;
    }
}
