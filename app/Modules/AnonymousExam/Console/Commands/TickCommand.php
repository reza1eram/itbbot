<?php

namespace App\Modules\AnonymousExam\Console\Commands;

use App\Modules\AnonymousExam\Services\ExamLifecycleService;
use Illuminate\Console\Command;

class TickCommand extends Command
{
    protected $signature = 'exam:tick';

    protected $description = 'Open scheduled exams, close ended ones, send reminders (runs every minute)';

    public function handle(ExamLifecycleService $lifecycle): int
    {
        $report = $lifecycle->tick();
        \App\Modules\AnonymousExam\Services\SchedulerHealth::markTick('cron');
        $this->line(sprintf('opened=%d closed=%d reminders=%d', $report['opened'], $report['closed'], $report['reminders']));

        return self::SUCCESS;
    }
}
