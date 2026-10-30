<?php

namespace App\Modules\AnonymousExam\Services;

use App\Modules\AnonymousExam\Models\Setting;
use Carbon\Carbon;
use Throwable;

/**
 * Remembers when the minute task last ran, and from where (cron = exam:tick, web = backup scheduler),
 * so the panel can warn when the cron job is not running.
 */
class SchedulerHealth
{
    public static function markTick(string $source): void
    {
        try {
            Setting::putValue('last_tick_' . $source, (string) now()->getTimestamp());
        } catch (Throwable) {
            // never break the tick because of bookkeeping
        }
    }

    public static function lastTick(string $source): ?Carbon
    {
        $ts = Setting::getValue('last_tick_' . $source);

        return $ts ? Carbon::createFromTimestamp((int) $ts) : null;
    }

    /** true when the cron job (exam:tick) has run within the last 5 minutes */
    public static function cronHealthy(): bool
    {
        $last = self::lastTick('cron');

        return $last !== null && $last->gt(now()->subMinutes(5));
    }
}
