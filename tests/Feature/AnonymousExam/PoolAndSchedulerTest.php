<?php

namespace Tests\Feature\AnonymousExam;

use App\Modules\AnonymousExam\Models\PoolEntry;
use App\Modules\AnonymousExam\Models\Response;
use App\Modules\AnonymousExam\Services\SchedulerHealth;
use App\Modules\AnonymousExam\Services\SubmissionService;
use App\Modules\AnonymousExam\Services\TokenExchangeService;
use Illuminate\Support\Facades\Artisan;

/** Pool mode with a broken cron: answers must be visible as "waiting" and releasable from the panel */
class PoolAndSchedulerTest extends AxTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['anonymous_exam.anon.pool_mode' => true, 'anonymous_exam.anon.pool_flush_min_batch' => 3]);
        $this->app->forgetInstance(SubmissionService::class);
    }

    private function submitPooled($exam, int $userId): void
    {
        $payload = app(TokenExchangeService::class)->exchange($this->tokenFor($exam, $userId))['payload'];
        $this->assertTrue(app(SubmissionService::class)->submit($payload, $this->correctAnswers($exam))['ok']);
    }

    public function test_results_page_shows_waiting_answers_and_flush_respects_minimum_batch(): void
    {
        $exam = $this->makeOpenQuiz(null, ['results_visibility' => 'live', 'min_responses_to_show' => 1]);
        $this->actingAs($this->makeAdmin('editor'), 'ax_admin');

        $this->submitPooled($exam, 1);
        $this->submitPooled($exam, 2);
        $this->get('/exam-admin/exams/' . $exam->id . '/results')->assertOk()->assertSee('پاسخ رمزنگاری‌شده در صف انتقال است');

        // only 2 waiting while open: nothing is released alone
        $this->post('/exam-admin/exams/' . $exam->id . '/flush-pool')->assertSessionHas('error');
        $this->assertSame(0, Response::query()->count());

        $this->submitPooled($exam, 3);
        $this->post('/exam-admin/exams/' . $exam->id . '/flush-pool')->assertSessionHas('success');
        $this->assertSame(3, Response::query()->count());
        $this->assertSame(0, PoolEntry::query()->count());
    }

    public function test_viewer_cannot_flush(): void
    {
        $exam = $this->makeOpenQuiz();
        $this->actingAs($this->makeAdmin('viewer'), 'ax_admin');
        $this->post('/exam-admin/exams/' . $exam->id . '/flush-pool');
        $this->assertDatabaseMissing('ax_audit_logs', ['action' => 'exam.flush_pool']);
    }

    public function test_cron_tick_is_recorded_for_the_health_warning(): void
    {
        $this->assertFalse(SchedulerHealth::cronHealthy());
        Artisan::call('exam:tick');
        $this->assertTrue(SchedulerHealth::cronHealthy());
    }
}
