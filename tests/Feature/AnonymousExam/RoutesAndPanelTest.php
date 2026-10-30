<?php

namespace Tests\Feature\AnonymousExam;

use App\Modules\AnonymousExam\Models\Exam;
use Illuminate\Support\Facades\Route;

/** Test 9 (no logging middleware on /x), panel auth/roles, root redirect, webhook secret */
class RoutesAndPanelTest extends AxTestCase
{
    public function test_participant_routes_have_no_logging_middleware(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'x'));
        $this->assertGreaterThanOrEqual(4, $routes->count());

        foreach ($routes as $route) {
            $middleware = Route::gatherRouteMiddleware($route);
            foreach ($middleware as $m) {
                $name = is_string($m) ? $m : get_class($m);
                $this->assertDoesNotMatchRegularExpression('/log|activity|telescope|report|audit|debugbar|ray/i', $name, "route {$route->uri()} has $name");
            }
        }

        // global middleware too
        foreach (app(\Illuminate\Contracts\Http\Kernel::class)->getGlobalMiddleware() as $m) {
            $this->assertDoesNotMatchRegularExpression('/log|activity|telescope/i', $m);
        }
    }

    public function test_participant_pages_send_privacy_headers(): void
    {
        $this->get('/x')
            ->assertStatus(410)
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');
    }

    public function test_invalid_and_unknown_tokens_show_error_page(): void
    {
        $this->get('/x/' . str_repeat('a', 43))->assertStatus(410)->assertSee('معتبر نیست');
    }

    public function test_admin_login_and_role_enforcement(): void
    {
        $this->get('/exam-admin')->assertRedirect('/exam-admin/login');
        $this->get('/exam-admin/login')->assertOk();

        $viewer = $this->makeAdmin('viewer');
        $this->post('/exam-admin/login', ['username' => $viewer->username, 'password' => 'wrong-password'])->assertSessionHas('error');

        $this->actingAs($viewer, 'ax_admin');
        $this->get('/exam-admin')->assertOk();
        $exam = $this->makeOpenQuiz();
        $this->get('/exam-admin/exams/' . $exam->id)->assertOk();
        $this->get('/exam-admin/exams/' . $exam->id . '/results')->assertOk();

        // viewer cannot change anything
        $this->post('/exam-admin/exams/' . $exam->id . '/close', ['confirm_code' => $exam->public_code]);
        $this->assertSame(Exam::STATUS_OPEN, $exam->refresh()->status);
        $this->postJson('/exam-admin/api/exams', [])->assertStatus(403);
    }

    public function test_editor_can_build_publish_and_close_with_confirmation(): void
    {
        $editor = $this->makeAdmin('editor');
        $group = $this->makeGroup(-100555);
        $this->actingAs($editor, 'ax_admin');

        $res = $this->postJson('/exam-admin/api/exams', [
            'title' => 'نظرسنجی', 'mode' => 'survey', 'group_id' => $group->id, 'display_mode' => 'single_page',
            'show_result_to_voter' => 'none', 'results_visibility' => 'after_close', 'min_responses_to_show' => 5,
            'ends_at' => now()->addDay()->setTimezone('Asia/Tehran')->format('Y-m-d\TH:i'),
            'announce_on_open' => true, 'announce_on_close' => true, 'reminders' => '60, 10',
            'questions' => [
                ['type' => 'single', 'text' => 'رنگ؟', 'is_required' => true, 'options' => [['text' => 'آبی'], ['text' => 'سبز']]],
                ['type' => 'long_text', 'text' => 'توضیح', 'is_required' => false],
            ],
        ])->assertOk()->assertJson(['ok' => true]);
        $exam = Exam::query()->findOrFail($res->json('id'));
        $this->assertSame(2, $exam->questions()->count());
        $this->assertSame([60, 10], $exam->reminders);

        $this->post('/exam-admin/exams/' . $exam->id . '/publish')->assertRedirect();
        $this->assertSame(Exam::STATUS_OPEN, $exam->refresh()->status);
        $this->assertTrue(collect($this->bale->sent())->contains(fn ($m) => $m['params']['chat_id'] == -100555), 'announcement sent to group');

        // structure is locked after open: questions are not replaced
        $this->putJson('/exam-admin/api/exams/' . $exam->id, $this->builderPayload($exam, ['questions' => []]))->assertOk();
        $this->assertSame(2, $exam->questions()->count());

        // wrong confirmation code does not close
        $this->post('/exam-admin/exams/' . $exam->id . '/close', ['confirm_code' => 'nope'])->assertSessionHas('error');
        $this->assertSame(Exam::STATUS_OPEN, $exam->refresh()->status);

        $this->post('/exam-admin/exams/' . $exam->id . '/close', ['confirm_code' => $exam->public_code])->assertRedirect();
        $this->assertSame(Exam::STATUS_CLOSED, $exam->refresh()->status);
        $this->assertDatabaseHas('ax_audit_logs', ['action' => 'exam.close', 'admin_id' => $editor->id]);
    }

    public function test_bulk_import_parses_text_format(): void
    {
        $this->actingAs($this->makeAdmin('editor'), 'ax_admin');
        $raw = "پایتخت ایران؟\n- اصفهان\n* تهران\n\n[long] نظر شما؟\n\n[tf] زمین گرد است";
        $qs = $this->postJson('/exam-admin/api/import', ['raw' => $raw, 'format' => 'text'])->assertOk()->json('questions');

        $this->assertCount(3, $qs);
        $this->assertSame('single', $qs[0]['type']);
        $this->assertTrue($qs[0]['options'][1]['is_correct']);
        $this->assertSame('long_text', $qs[1]['type']);
        $this->assertSame('true_false', $qs[2]['type']);
    }

    public function test_root_redirects_to_the_panel(): void
    {
        $this->get('/')->assertRedirect('/exam-admin');
    }

    public function test_webhook_requires_the_secret_path(): void
    {
        config(['anonymous_exam.bale.webhook_secret_path' => 'correct-secret-path-123']);
        $this->postJson('/api/bale/exam/wrong-secret-path-1234', ['update_id' => 1])->assertNotFound();
        $this->postJson('/api/bale/exam/correct-secret-path-123', ['update_id' => 1])->assertOk();
    }

    private function builderPayload(Exam $exam, array $override): array
    {
        return array_merge([
            'title' => $exam->title, 'mode' => $exam->mode, 'group_id' => $exam->group_id, 'display_mode' => 'single_page',
            'show_result_to_voter' => 'none', 'results_visibility' => 'after_close', 'min_responses_to_show' => 5,
            'ends_at' => $exam->ends_at->setTimezone('Asia/Tehran')->format('Y-m-d\TH:i'),
        ], $override);
    }
}
