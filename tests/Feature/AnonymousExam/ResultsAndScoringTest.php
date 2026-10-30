<?php

namespace Tests\Feature\AnonymousExam;

use App\Modules\AnonymousExam\Models\Exam;
use App\Modules\AnonymousExam\Models\Question;
use App\Modules\AnonymousExam\Services\ExamLifecycleService;
use App\Modules\AnonymousExam\Services\ResultsService;
use App\Modules\AnonymousExam\Services\ScoringService;
use App\Modules\AnonymousExam\Services\SubmissionService;
use App\Modules\AnonymousExam\Services\TokenExchangeService;

/** Tests 6 and 7: small-group rules, random text order, scoring */
class ResultsAndScoringTest extends AxTestCase
{
    private function submitFor(Exam $exam, int $userId, array $answers): array
    {
        $payload = app(TokenExchangeService::class)->exchange($this->tokenFor($exam, $userId))['payload'];

        return app(SubmissionService::class)->submit($payload, $answers);
    }

    public function test_scoring_single_multi_true_false(): void
    {
        $exam = $this->makeOpenQuiz();
        $qs = $exam->questions()->with('options')->get();
        $scoring = app(ScoringService::class);
        [$single, $multi, $tf] = [$qs[0], $qs[1], $qs[2]];

        $all = $scoring->score($exam, $qs, [
            $single->id => ['option_ids' => [$single->options->firstWhere('text', 'B')->id]],
            $multi->id => ['option_ids' => $multi->options->where('is_correct', true)->pluck('id')->all()],
            $tf->id => ['option_ids' => [$tf->options->firstWhere('is_correct', true)->id]],
        ]);
        $this->assertSame(6.0, $all['score']);
        $this->assertSame(6.0, $all['max_score']);

        // multi: partial selection gets nothing; wrong single and wrong tf get nothing
        $partial = $scoring->score($exam, $qs, [
            $single->id => ['option_ids' => [$single->options->firstWhere('text', 'A')->id]],
            $multi->id => ['option_ids' => [$multi->options->firstWhere('text', 'A')->id]],
            $tf->id => ['option_ids' => [$tf->options->firstWhere('is_correct', false)->id]],
        ]);
        $this->assertSame(0.0, $partial['score']);

        // multi with an extra wrong option gets nothing
        $extra = $scoring->score($exam, $qs, [$multi->id => ['option_ids' => $multi->options->pluck('id')->all()]]);
        $this->assertSame(0.0, $extra['points'][$multi->id]);

        // survey mode is never scored
        $exam->mode = 'survey';
        $this->assertNull($scoring->score($exam, $qs, [])['score']);
    }

    public function test_score_shown_to_voter_after_submit(): void
    {
        $exam = $this->makeOpenQuiz();
        $result = $this->submitFor($exam, 10, $this->correctAnswers($exam));
        $this->assertSame(6.0, $result['result']['score']);
    }

    public function test_details_hidden_below_minimum_and_until_close(): void
    {
        $exam = $this->makeOpenQuiz(null, ['min_responses_to_show' => 3]);
        $results = app(ResultsService::class);

        $this->submitFor($exam, 1, $this->correctAnswers($exam, 'یک'));
        $this->submitFor($exam, 2, $this->correctAnswers($exam, 'دو'));

        $this->assertSame('locked_until_close', $results->visibility($exam)['reason']);

        app(ExamLifecycleService::class)->close($exam, null);
        $exam->refresh();
        $summary = $results->summary($exam);
        $this->assertFalse($summary['visibility']['visible']);
        $this->assertSame('below_minimum', $summary['visibility']['reason']);
        $this->assertArrayNotHasKey('questions', $summary);
        $this->assertNull($results->csvRows($exam));
        $this->assertNull($results->scoreLine($exam), 'close message must not reveal the average of a tiny group');
        $this->assertSame(2, $summary['counts']['submitted'], 'the plain count is always visible');
    }

    public function test_live_visibility_still_respects_minimum(): void
    {
        $exam = $this->makeOpenQuiz(null, ['min_responses_to_show' => 2, 'results_visibility' => 'live']);
        $results = app(ResultsService::class);

        $this->submitFor($exam, 1, $this->correctAnswers($exam));
        $this->assertFalse($results->visibility($exam)['visible']);
        $this->submitFor($exam, 2, $this->correctAnswers($exam));
        $this->assertTrue($results->visibility($exam)['visible']);
    }

    public function test_text_answers_and_csv_rows_are_not_in_submission_order(): void
    {
        $exam = $this->makeOpenQuiz(null, ['min_responses_to_show' => 1]);
        $texts = [];
        for ($i = 1; $i <= 12; $i++) {
            $texts[] = 'پاسخ ' . $i;
            $this->submitFor($exam, 100 + $i, $this->correctAnswers($exam, 'پاسخ ' . $i));
        }
        app(ExamLifecycleService::class)->close($exam, null);
        $exam->refresh();
        $results = app(ResultsService::class);
        $textQ = $exam->questions()->where('type', 'short_text')->first();

        $differs = false;
        for ($run = 0; $run < 5 && !$differs; $run++) {
            $item = collect($results->summary($exam)['questions'])->firstWhere('id', $textQ->id);
            $this->assertEqualsCanonicalizing($texts, $item['texts']);
            $differs = $item['texts'] !== $texts;
        }
        $this->assertTrue($differs, 'texts must be shuffled');

        $rows = $results->csvRows($exam);
        $this->assertCount(13, $rows);
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                $this->assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2}|\d{2}:\d{2}/', (string) $cell, 'CSV must not contain times');
            }
        }
    }

    public function test_pool_mode_holds_responses_encrypted_until_flushed_on_close(): void
    {
        config(['anonymous_exam.anon.pool_mode' => true]);
        $this->app->forgetInstance(SubmissionService::class);
        $exam = $this->makeOpenQuiz(null, ['min_responses_to_show' => 1]);

        $this->assertTrue($this->submitFor($exam, 1, $this->correctAnswers($exam, 'محرمانه'))['ok']);
        $this->assertSame(0, \App\Modules\AnonymousExam\Models\Response::query()->count());
        $pooled = \App\Modules\AnonymousExam\Models\PoolEntry::query()->firstOrFail();
        $this->assertStringNotContainsString('محرمانه', $pooled->payload);

        app(ExamLifecycleService::class)->close($exam, null);
        $this->assertSame(1, \App\Modules\AnonymousExam\Models\Response::query()->count());
        $this->assertSame(0, \App\Modules\AnonymousExam\Models\PoolEntry::query()->count());
    }

    public function test_validation_rules_per_question_type(): void
    {
        $exam = $this->makeOpenQuiz();
        Question::query()->create(['exam_id' => $exam->id, 'position' => 9, 'type' => 'rating', 'text' => 'امتیاز', 'is_required' => true, 'config' => ['min' => 1, 'max' => 5]]);
        $payload = app(TokenExchangeService::class)->exchange($this->tokenFor($exam, 77))['payload'];
        $rating = $exam->questions()->where('type', 'rating')->first();

        $bad = $this->correctAnswers($exam) + [$rating->id => '9'];
        $res = app(SubmissionService::class)->submit($payload, $bad);
        $this->assertSame('out_of_range', $res['errors'][$rating->id]);

        $missing = $this->correctAnswers($exam);
        $res = app(SubmissionService::class)->submit($payload, $missing);
        $this->assertSame('required', $res['errors'][$rating->id]);

        $ok = $this->correctAnswers($exam) + [$rating->id => '۴'];
        $this->assertTrue(app(SubmissionService::class)->submit($payload, $ok)['ok'], 'Persian digits are accepted');
    }
}
