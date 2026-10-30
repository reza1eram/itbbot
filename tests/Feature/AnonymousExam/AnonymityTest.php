<?php

namespace Tests\Feature\AnonymousExam;

use App\Modules\AnonymousExam\Models\Answer;
use App\Modules\AnonymousExam\Models\Participation;
use App\Modules\AnonymousExam\Models\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use ReflectionMethod;

/** Test 5: structural anonymity – no path from responses back to people */
class AnonymityTest extends AxTestCase
{
    private const FORBIDDEN_COLUMNS = ['user_id', 'chat_user', 'chat_user_id', 'from_id', 'ip', 'ip_address', 'user_agent', 'ua', 'participation_id_hint', 'voter_hash'];

    private function axTables(): array
    {
        $db = DB::connection()->getDatabaseName();

        return collect(DB::select('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ?', [$db, 'ax\_%']))->pluck('t')->all();
    }

    private function columns(string $table): array
    {
        $db = DB::connection()->getDatabaseName();

        return collect(DB::select('SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $table]))->pluck('c')->map(fn ($c) => strtolower($c))->all();
    }

    public function test_no_identity_columns_exist_in_module_tables(): void
    {
        $tables = $this->axTables();
        $this->assertNotEmpty($tables);

        foreach ($tables as $table) {
            $cols = $this->columns($table);
            foreach (['user_id', 'chat_user', 'chat_user_id', 'from_id', 'ip', 'ip_address', 'user_agent', 'ua'] as $bad) {
                $this->assertNotContains($bad, $cols, "$table must not have column $bad");
            }
        }
    }

    public function test_response_world_has_no_columns_or_foreign_keys_into_identity_world(): void
    {
        foreach (['ax_responses', 'ax_answers', 'ax_response_pool'] as $table) {
            $cols = $this->columns($table);
            foreach (['participation_id', 'voter_hash', 'token_hash', 'created_at', 'updated_at'] as $bad) {
                $this->assertNotContains($bad, $cols, "$table must not have $bad");
            }
        }
        $this->assertNotContains('id', $this->columns('ax_answers'), 'answers must not have an increasing id');

        $db = DB::connection()->getDatabaseName();
        $fks = DB::select(
            'SELECT TABLE_NAME AS t, REFERENCED_TABLE_NAME AS r FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL AND TABLE_NAME IN (?, ?, ?)',
            [$db, 'ax_responses', 'ax_answers', 'ax_response_pool']
        );
        foreach ($fks as $fk) {
            $this->assertNotContains($fk->r, ['ax_participations', 'ax_access_tokens'], "{$fk->t} must not reference {$fk->r}");
        }

        foreach (['ax_participations', 'ax_access_tokens'] as $table) {
            $this->assertNotContains('response_id', $this->columns($table));
        }
    }

    public function test_models_expose_no_relation_between_the_two_worlds(): void
    {
        foreach ([Response::class, Answer::class, Participation::class] as $model) {
            foreach ((new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->class !== $model) {
                    continue;
                }
                $source = implode('', array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
                $forbidden = $model === Participation::class ? ['Response', 'Answer'] : ['Participation', 'AccessToken'];
                foreach ($forbidden as $word) {
                    $this->assertStringNotContainsString($word . '::class', $source, "$model::{$method->name} references $word");
                }
            }
        }
    }

    public function test_after_submit_nothing_links_the_response_to_the_person(): void
    {
        $exam = $this->makeOpenQuiz();
        $userId = 987654321012;
        $this->participate($exam, $userId, $this->correctAnswers($exam, 'نظر من'))->assertOk();

        $response = Response::query()->firstOrFail();
        $participation = Participation::query()->firstOrFail();

        // random UUID v4, unrelated to the participation id
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $response->id);
        $this->assertNotSame($participation->id, $response->id);

        // only hour precision is stored
        $this->assertSame('00:00', $response->submitted_hour->format('i:s'));

        // the raw user id appears in no module table
        foreach ($this->axTables() as $table) {
            foreach (DB::table($table)->get() as $row) {
                $this->assertStringNotContainsString((string) $userId, json_encode($row), "user id leaked into $table");
            }
        }

        // voter hash is an HMAC, not a plain hash of the id
        $this->assertNotSame(hash('sha256', $userId . '|' . $exam->id), $participation->voter_hash);
    }

    public function test_user_id_is_not_written_to_logs_during_the_flow(): void
    {
        $exam = $this->makeOpenQuiz();
        $userId = 112233445566;
        $logged = [];
        Log::listen(function ($event) use (&$logged) {
            $logged[] = $event->message . ' ' . json_encode($event->context);
        });

        // include failing paths that log (bad membership API, invalid input)
        $this->bale->memberStatus[$userId] = 'member';
        app(\App\Modules\AnonymousExam\Services\BotUpdateHandler::class)->handle([
            'update_id' => 5,
            'message' => ['text' => '/start exam_' . $exam->public_code, 'chat' => ['id' => $userId, 'type' => 'private'], 'from' => ['id' => $userId]],
        ]);
        $url = $this->bale->sent()[0]['params']['reply_markup']['inline_keyboard'][0][0]['url'];
        $this->carrySession($this->get(parse_url($url, PHP_URL_PATH)));
        $this->carrySession($this->postJson('/x/submit', ['answers' => ['abc' => 'x']]));
        $this->carrySession($this->postJson('/x/submit', ['answers' => $this->correctAnswers($exam)]))->assertOk();

        foreach ($logged as $line) {
            $this->assertStringNotContainsString((string) $userId, $line);
            $this->assertStringNotContainsString(basename($url), $line, 'token leaked into log');
        }
        $this->assertSame(0, DB::table('ax_audit_logs')->where('action', 'like', '%submit%')->count(), 'participant events must not be audited');
    }
}
