<?php

namespace Tests\Feature\AnonymousExam;

use App\Modules\AnonymousExam\Bale\BaleApi;
use App\Modules\AnonymousExam\Models\AdminUser;
use App\Modules\AnonymousExam\Models\Exam;
use App\Modules\AnonymousExam\Models\Group;
use App\Modules\AnonymousExam\Models\Option;
use App\Modules\AnonymousExam\Models\Question;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AnonymousExam\Support\FakeBale;
use Tests\TestCase;

/**
 * Module tests run against a separate MySQL database (AX_TEST_DB, default bale_exam_bot_test)
 * containing only the module's migrations, so legacy migrations never get in the way.
 */
abstract class AxTestCase extends TestCase
{
    use DatabaseTransactions;

    protected static bool $migrated = false;

    protected FakeBale $bale;

    public function createApplication(): \Illuminate\Foundation\Application
    {
        $app = parent::createApplication();
        $app['config']->set('database.connections.mysql.database', env('AX_TEST_DB', 'bale_exam_bot_test'));
        $app['config']->set('anonymous_exam.anon.hmac_secret', str_repeat('k', 64));
        $app['config']->set('anonymous_exam.bale.token', '123456:TEST-TOKEN-abcdefghijklmnop');
        $app['config']->set('anonymous_exam.bale.membership_mode', 'getchatmember');
        $app['config']->set('anonymous_exam.anon.pool_mode', false);
        $app['config']->set('app.url', 'http://localhost');
        $app['config']->set('anonymous_exam.public_url', 'http://localhost');
        DB::purge('mysql');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$migrated) {
            DB::rollBack(); // DDL must not run inside the test transaction
            Artisan::call('migrate:fresh', ['--path' => 'database/migrations/anonymous_exam', '--force' => true]);
            static::$migrated = true;
            DB::beginTransaction();
        }

        $this->bale = new FakeBale();
        $this->app->instance(BaleApi::class, $this->bale);
        \Illuminate\Support\Facades\Cache::flush();
    }

    protected function makeGroup(int $chatId = -1001234567890): Group
    {
        return Group::query()->create(['chat_id' => $chatId, 'title' => 'گروه آزمایشی', 'is_active' => true, 'registered_at' => now(), 'members_count' => 20]);
    }

    /** Open quiz with: single (correct=B), multi (correct=A,C), true_false (correct=درست), short_text */
    protected function makeOpenQuiz(?Group $group = null, array $overrides = []): Exam
    {
        $group ??= $this->makeGroup();
        $exam = Exam::query()->create(array_merge([
            'public_code' => 'code' . random_int(1000, 9999),
            'title' => 'آزمون نمونه',
            'group_id' => $group->id,
            'mode' => 'quiz',
            'status' => Exam::STATUS_OPEN,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
            'min_responses_to_show' => 2,
            'display_mode' => 'single_page',
            'show_result_to_voter' => 'score',
            'results_visibility' => 'after_close',
        ], $overrides));

        $single = Question::query()->create(['exam_id' => $exam->id, 'position' => 0, 'type' => 'single', 'text' => 'تک', 'is_required' => true, 'points' => 2]);
        foreach (['A', 'B', 'C'] as $i => $t) {
            Option::query()->create(['question_id' => $single->id, 'position' => $i, 'text' => $t, 'is_correct' => $t === 'B']);
        }
        $multi = Question::query()->create(['exam_id' => $exam->id, 'position' => 1, 'type' => 'multi', 'text' => 'چند', 'is_required' => true, 'points' => 3]);
        foreach (['A', 'B', 'C'] as $i => $t) {
            Option::query()->create(['question_id' => $multi->id, 'position' => $i, 'text' => $t, 'is_correct' => $t !== 'B']);
        }
        $tf = Question::query()->create(['exam_id' => $exam->id, 'position' => 2, 'type' => 'true_false', 'text' => 'درست؟', 'is_required' => true, 'points' => 1]);
        Option::query()->create(['question_id' => $tf->id, 'position' => 0, 'text' => 'درست', 'is_correct' => true]);
        Option::query()->create(['question_id' => $tf->id, 'position' => 1, 'text' => 'نادرست', 'is_correct' => false]);
        Question::query()->create(['exam_id' => $exam->id, 'position' => 3, 'type' => 'short_text', 'text' => 'نظر', 'is_required' => false, 'points' => 0]);

        return $exam->refresh();
    }

    /** Correct answers for makeOpenQuiz(), optionally with a text */
    protected function correctAnswers(Exam $exam, ?string $text = null): array
    {
        $qs = $exam->questions()->with('options')->get();
        $out = [];
        foreach ($qs as $q) {
            if ($q->type === 'multi') {
                $out[$q->id] = $q->options->where('is_correct', true)->pluck('id')->map(fn ($v) => (string) $v)->values()->all();
            } elseif ($q->isChoice()) {
                $out[$q->id] = (string) $q->options->firstWhere('is_correct', true)->id;
            } elseif ($text !== null) {
                $out[$q->id] = $text;
            }
        }

        return $out;
    }

    protected function makeAdmin(string $role = 'owner'): AdminUser
    {
        return AdminUser::query()->create([
            'name' => 'مدیر', 'username' => $role . random_int(100, 999), 'password' => 'secret-password-1', 'role' => $role, 'is_active' => true,
        ]);
    }

    /** Issues an access link for $userId and returns the plain token from the URL */
    protected function tokenFor(Exam $exam, int $userId): string
    {
        $this->bale->memberStatus[$userId] = 'member';
        $result = app(\App\Modules\AnonymousExam\Services\AccessService::class)->requestAccess($exam, $userId);
        $this->assertTrue($result->isOk(), 'access should be granted, got ' . $result->status);

        return basename((string) $result->url);
    }

    /** Full participant flow over HTTP: exchange -> form -> submit */
    protected function participate(Exam $exam, int $userId, array $answers): \Illuminate\Testing\TestResponse
    {
        $token = $this->tokenFor($exam, $userId);
        $this->carrySession($this->get('/x/' . $token)->assertRedirect('/x'));
        $this->carrySession($this->get('/x')->assertOk());

        return $this->carrySession($this->postJson('/x/submit', ['answers' => $answers]));
    }

    /** The test client does not keep cookies between requests; forward the session cookie explicitly */
    protected function carrySession(\Illuminate\Testing\TestResponse $response): \Illuminate\Testing\TestResponse
    {
        $cookie = $response->getCookie(config('session.cookie'), true);
        if ($cookie) {
            $this->withCookie(config('session.cookie'), $cookie->getValue());
        }

        return $response;
    }
}
