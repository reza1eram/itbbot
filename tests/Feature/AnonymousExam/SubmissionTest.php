<?php

namespace Tests\Feature\AnonymousExam;

use App\Modules\AnonymousExam\Models\Exam;
use App\Modules\AnonymousExam\Models\Participation;
use App\Modules\AnonymousExam\Models\Response;
use App\Modules\AnonymousExam\Services\AccessService;
use App\Modules\AnonymousExam\Services\ExamLifecycleService;
use App\Modules\AnonymousExam\Services\SubmissionService;
use App\Modules\AnonymousExam\Services\TokenExchangeService;
use App\Modules\AnonymousExam\Support\AccessResult;

/** Tests 2, 3, 4, 8: one vote per person, token rules, time window, closing */
class SubmissionTest extends AxTestCase
{
    public function test_full_flow_and_only_one_submission_per_person(): void
    {
        $exam = $this->makeOpenQuiz();

        $this->participate($exam, 1001, $this->correctAnswers($exam))->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(1, Response::query()->count());

        // the same person cannot get a new link afterwards
        $this->assertSame(AccessResult::ALREADY_SUBMITTED, app(AccessService::class)->requestAccess($exam, 1001)->status);
        $this->assertSame(1, Participation::query()->where('state', 'submitted')->count());
    }

    public function test_two_submits_from_the_same_session_store_only_one_response(): void
    {
        $exam = $this->makeOpenQuiz();
        $exchange = app(TokenExchangeService::class)->exchange($this->tokenFor($exam, 2002));
        $service = app(SubmissionService::class);

        $first = $service->submit($exchange['payload'], $this->correctAnswers($exam));
        $second = $service->submit($exchange['payload'], $this->correctAnswers($exam));

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertSame(SubmissionService::ERR_ALREADY, $second['error']);
        $this->assertSame(1, Response::query()->count());
    }

    public function test_two_parallel_links_of_one_person_still_allow_only_one_response(): void
    {
        $exam = $this->makeOpenQuiz();
        // two sessions opened from two links before either submits (second link revokes the first unused one,
        // so we exchange the first before requesting the second)
        $a = app(TokenExchangeService::class)->exchange($this->tokenFor($exam, 3003));
        $b = app(TokenExchangeService::class)->exchange($this->tokenFor($exam, 3003));
        $this->assertTrue($a['ok'] && $b['ok']);

        $service = app(SubmissionService::class);
        $results = [$service->submit($a['payload'], $this->correctAnswers($exam)), $service->submit($b['payload'], $this->correctAnswers($exam))];

        $this->assertSame(1, collect($results)->where('ok', true)->count());
        $this->assertSame(1, Response::query()->count());
    }

    public function test_token_is_single_use(): void
    {
        $exam = $this->makeOpenQuiz();
        $token = $this->tokenFor($exam, 4004);
        $exchange = app(TokenExchangeService::class);

        $this->assertTrue($exchange->exchange($token)['ok']);
        $this->assertSame(TokenExchangeService::ERR_USED, $exchange->exchange($token)['error']);
    }

    public function test_token_expires(): void
    {
        $exam = $this->makeOpenQuiz();
        $token = $this->tokenFor($exam, 4005);

        $this->travel(21)->minutes();

        $this->assertSame(TokenExchangeService::ERR_EXPIRED, app(TokenExchangeService::class)->exchange($token)['error']);
    }

    public function test_new_link_revokes_the_previous_unused_link(): void
    {
        $exam = $this->makeOpenQuiz();
        $old = $this->tokenFor($exam, 4006);
        $new = $this->tokenFor($exam, 4006);

        $this->assertSame(TokenExchangeService::ERR_USED, app(TokenExchangeService::class)->exchange($old)['error']);
        $this->assertTrue(app(TokenExchangeService::class)->exchange($new)['ok']);
    }

    public function test_token_is_bound_to_its_exam(): void
    {
        $examA = $this->makeOpenQuiz();
        $examB = $this->makeOpenQuiz($examA->group);
        $payload = app(TokenExchangeService::class)->exchange($this->tokenFor($examA, 4007))['payload'];

        $this->assertSame($examA->id, $payload['exam_id']);

        // forging the session to point at exam B is rejected (participation belongs to A)
        $payload['exam_id'] = $examB->id;
        $result = app(SubmissionService::class)->submit($payload, $this->correctAnswers($examB));
        $this->assertSame(SubmissionService::ERR_ALREADY, $result['error']);
        $this->assertSame(0, Response::query()->count());
    }

    public function test_options_of_another_exam_are_rejected(): void
    {
        $examA = $this->makeOpenQuiz();
        $examB = $this->makeOpenQuiz($examA->group);
        $payload = app(TokenExchangeService::class)->exchange($this->tokenFor($examA, 4008))['payload'];

        $answers = $this->correctAnswers($examA);
        $firstA = $examA->questions()->first();
        $answers[$firstA->id] = (string) $examB->questions()->first()->options()->first()->id;

        $result = app(SubmissionService::class)->submit($payload, $answers);
        $this->assertSame(SubmissionService::ERR_VALIDATION, $result['error']);
        $this->assertSame('invalid_option', $result['errors'][$firstA->id]);

        // question ids of another exam are rejected too
        $result = app(SubmissionService::class)->submit($payload, $this->correctAnswers($examA) + [$examB->questions()->first()->id => 'x']);
        $this->assertSame(SubmissionService::ERR_VALIDATION, $result['error']);
    }

    public function test_submission_before_start_is_rejected(): void
    {
        $exam = $this->makeOpenQuiz(null, ['starts_at' => now()->addHour()]);
        $this->bale->memberStatus[5005] = 'member';

        $this->assertSame(AccessResult::NOT_STARTED, app(AccessService::class)->requestAccess($exam, 5005)->status);

        // even with a forged session, the server refuses
        $result = app(SubmissionService::class)->submit(['exam_id' => $exam->id, 'participation_id' => 'x', 'started_at' => time(), 'expires_at' => time() + 600], []);
        $this->assertSame(SubmissionService::ERR_CLOSED, $result['error']);

        $draft = $this->makeOpenQuiz($exam->group, ['status' => Exam::STATUS_SCHEDULED, 'public_code' => 'sched01']);
        $this->assertSame(AccessResult::NOT_STARTED, app(AccessService::class)->requestAccess($draft, 5005)->status);
    }

    public function test_closing_revokes_tokens_rejects_submissions_and_unlocks_results(): void
    {
        $exam = $this->makeOpenQuiz();
        $unused = $this->tokenFor($exam, 6006);
        $session = app(TokenExchangeService::class)->exchange($this->tokenFor($exam, 6007))['payload'];

        app(ExamLifecycleService::class)->close($exam, $this->makeAdmin());
        $exam->refresh();

        $this->assertSame(Exam::STATUS_CLOSED, $exam->status);
        $this->assertSame(TokenExchangeService::ERR_USED, app(TokenExchangeService::class)->exchange($unused)['error']);
        $this->assertSame(SubmissionService::ERR_CLOSED, app(SubmissionService::class)->submit($session, $this->correctAnswers($exam))['error']);
        $this->assertSame(AccessResult::ENDED, app(AccessService::class)->requestAccess($exam, 6008)->status);
        $this->assertSame('below_minimum', app(\App\Modules\AnonymousExam\Services\ResultsService::class)->visibility($exam)['reason']);
    }

    public function test_after_ends_at_submission_is_rejected_over_http(): void
    {
        $exam = $this->makeOpenQuiz(null, ['ends_at' => now()->addMinutes(30)]);
        $token = $this->tokenFor($exam, 7007);
        $this->carrySession($this->get('/x/' . $token));

        $this->travel(31)->minutes();

        $this->postJson('/x/submit', ['answers' => $this->correctAnswers($exam)])->assertStatus(410);
        $this->assertSame(0, Response::query()->count());
    }

    public function test_duration_limit_is_enforced_server_side(): void
    {
        $exam = $this->makeOpenQuiz(null, ['duration_minutes' => 5]);
        $session = app(TokenExchangeService::class)->exchange($this->tokenFor($exam, 7008))['payload'];
        $session['expires_at'] = time() + 3600; // even if the session were still alive

        $this->travel(7)->minutes();
        $this->assertSame(SubmissionService::ERR_TIME_OVER, app(SubmissionService::class)->submit($session, $this->correctAnswers($exam))['error']);
    }

    public function test_tick_opens_scheduled_and_closes_ended_exams(): void
    {
        $toOpen = $this->makeOpenQuiz(null, ['status' => Exam::STATUS_SCHEDULED, 'starts_at' => now()->subMinute(), 'public_code' => 'tickopen']);
        $toClose = $this->makeOpenQuiz($toOpen->group, ['ends_at' => now()->subMinute(), 'public_code' => 'tickclos']);

        app(ExamLifecycleService::class)->tick();

        $this->assertSame(Exam::STATUS_OPEN, $toOpen->refresh()->status);
        $this->assertSame(Exam::STATUS_CLOSED, $toClose->refresh()->status);
        $this->assertTrue(collect($this->bale->sent())->contains(fn ($m) => isset($m['params']['reply_markup']['inline_keyboard'])));
    }
}
