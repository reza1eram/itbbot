<?php

namespace App\Modules\AnonymousExam\Console\Commands;

use App\Modules\AnonymousExam\Models\AdminUser;
use Illuminate\Console\Command;
use Throwable;

/** Used by start.bat: exit code 0 when at least one active admin exists */
class HasAdminCommand extends Command
{
    protected $signature = 'exam:has-admin';

    protected $description = 'Exit 0 if an active panel admin exists, 1 otherwise';

    public function handle(): int
    {
        try {
            return AdminUser::query()->where('is_active', true)->exists() ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            return self::FAILURE;
        }
    }
}
