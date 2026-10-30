<?php

namespace App\Modules\AnonymousExam\Console\Commands;

use App\Modules\AnonymousExam\Services\PoolFlushService;
use Illuminate\Console\Command;

class FlushPoolCommand extends Command
{
    protected $signature = 'exam:flush-pool {--exam= : only this exam id} {--force : flush even small batches}';

    protected $description = 'Move pooled (encrypted) responses into the response tables in random order';

    public function handle(PoolFlushService $pool): int
    {
        $exam = $this->option('exam');
        $this->line('moved=' . $pool->flush($exam !== null ? (int) $exam : null, (bool) $this->option('force')));

        return self::SUCCESS;
    }
}
