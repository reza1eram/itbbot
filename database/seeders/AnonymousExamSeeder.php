<?php

namespace Database\Seeders;

use App\Modules\AnonymousExam\Models\AxBot;
use App\Modules\AnonymousExam\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Base data for the anonymous exam module: the bot record and default settings.
 * Idempotent; never overwrites values changed in the panel.
 *
 *   php artisan db:seed --class=AnonymousExamSeeder
 */
class AnonymousExamSeeder extends Seeder
{
    public function run(): void
    {
        AxBot::query()->firstOrCreate(
            ['username' => (string) config('anonymous_exam.bale.username', 'ITBbot')],
            ['update_mode' => (string) config('anonymous_exam.bale.update_mode', 'polling')]
        );

        $defaults = [
            'token_ttl_minutes' => (string) config('anonymous_exam.anon.token_ttl_minutes', 20),
            'default_min_responses' => (string) config('anonymous_exam.anon.min_responses_to_show', 5),
            'default_reminders' => implode(',', config('anonymous_exam.default_reminders', [60, 10])),
        ];
        foreach ($defaults as $key => $value) {
            Setting::query()->firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
