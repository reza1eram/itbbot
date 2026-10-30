<?php

namespace App\Modules\AnonymousExam\Services;

use App\Modules\AnonymousExam\Models\AccessToken;
use App\Modules\AnonymousExam\Models\Answer;
use App\Modules\AnonymousExam\Models\Exam;
use App\Modules\AnonymousExam\Models\Participation;
use App\Modules\AnonymousExam\Models\PoolEntry;
use App\Modules\AnonymousExam\Models\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores a submission. In ONE transaction (row locks on exam + participation):
 *   identity world:  participation.state = submitted
 *   response world:  new Response (random UUID, hour-rounded time) + Answers
 * Nothing written to the response world refers to the participation, the token or the user.
 */
class SubmissionService
{
    public const ERR_SESSION = 'session_expired';
    public const ERR_CLOSED = 'closed';
    public const ERR_TIME_OVER = 'time_over';
    public const ERR_ALREADY = 'already_submitted';
    public const ERR_VALIDATION = 'validation';

    public function __construct(
        private readonly AnswerValidator $validator,
        private readonly ScoringService $scoring,
        private readonly bool $poolMode = false,
    ) {
    }

    /**
     * @param array $session payload created by TokenExchangeService
     * @return array{ok:bool,error?:string,errors?:array,result?:array}
     */
    public function submit(array $session, array $input): array
    {
        if (empty($session['exam_id']) || empty($session['participation_id']) || ($session['expires_at'] ?? 0) < now()->getTimestamp()) {
            return ['ok' => false, 'error' => self::ERR_SESSION];
        }

        $exam = Exam::query()->find((int) $session['exam_id']);
        if (!$exam || !$exam->acceptsSubmissions()) {
            return ['ok' => false, 'error' => self::ERR_CLOSED];
        }

        if ($exam->duration_minutes) {
            $deadline = (int) $session['started_at'] + ($exam->duration_minutes * 60) + 60;
            if (now()->getTimestamp() > $deadline) {
                return ['ok' => false, 'error' => self::ERR_TIME_OVER];
            }
        }

        $validated = $this->validator->validate($exam, $input);
        if ($validated['errors'] !== []) {
            return ['ok' => false, 'error' => self::ERR_VALIDATION, 'errors' => $validated['errors']];
        }

        $questions = $exam->questions()->with('options')->get();
        $score = $this->scoring->score($exam, $questions, $validated['answers']);

        $stored = DB::transaction(function () use ($exam, $session, $validated, $score) {
            // Lock order everywhere: exam, then participation. Closing the exam takes the same exam lock.
            $lockedExam = Exam::query()->whereKey($exam->id)->lockForUpdate()->first();
            if (!$lockedExam || !$lockedExam->acceptsSubmissions()) {
                return self::ERR_CLOSED;
            }

            $participation = Participation::query()
                ->whereKey($session['participation_id'])
                ->where('exam_id', $exam->id)
                ->lockForUpdate()
                ->first();
            if (!$participation || $participation->state === Participation::STATE_SUBMITTED) {
                return self::ERR_ALREADY;
            }

            $participation->state = Participation::STATE_SUBMITTED;
            $participation->save();

            AccessToken::query()->where('participation_id', $participation->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

            $this->poolMode
                ? $this->storeInPool($exam->id, $validated['answers'], $score)
                : self::storeResponse($exam->id, $validated['answers'], $score, now()->startOfHour());

            return true;
        });

        if ($stored !== true) {
            return ['ok' => false, 'error' => $stored];
        }

        return ['ok' => true, 'result' => $this->voterResult($exam, $questions, $validated['answers'], $score)];
    }

    /** Writes one response into the response world. Also used when flushing the pool. */
    public static function storeResponse(int $examId, array $answers, array $score, \DateTimeInterface $hour): void
    {
        $responseId = (string) Str::uuid(); // random v4, not time-ordered

        Response::query()->insert([
            'id' => $responseId,
            'exam_id' => $examId,
            'submitted_hour' => $hour->format('Y-m-d H:00:00'),
            'score' => $score['score'],
            'max_score' => $score['max_score'],
        ]);

        $rows = [];
        foreach ($answers as $questionId => $answer) {
            $rows[] = [
                'response_id' => $responseId,
                'question_id' => (int) $questionId,
                'option_ids' => isset($answer['option_ids']) ? json_encode($answer['option_ids']) : null,
                'text_value' => $answer['text_value'] ?? null,
                'number_value' => $answer['number_value'] ?? null,
                'points_awarded' => $score['points'][$questionId] ?? null,
            ];
        }
        if ($rows !== []) {
            Answer::query()->insert($rows);
        }
    }

    private function storeInPool(int $examId, array $answers, array $score): void
    {
        PoolEntry::query()->insert([
            'id' => (string) Str::uuid(),
            'exam_id' => $examId,
            'payload' => Crypt::encryptString(json_encode([
                'answers' => $answers,
                'score' => $score,
                'hour' => now()->startOfHour()->format('Y-m-d H:00:00'),
            ])),
        ]);
    }

    /** What the participant may see right after submitting (per show_result_to_voter) */
    private function voterResult(Exam $exam, $questions, array $answers, array $score): array
    {
        $out = ['show' => $exam->show_result_to_voter];
        if (!$exam->isQuiz() || $exam->show_result_to_voter === 'none') {
            return $out;
        }
        $out['score'] = $score['score'];
        $out['max_score'] = $score['max_score'];

        if ($exam->show_result_to_voter === 'score_and_answers') {
            $out['questions'] = $questions->filter->isChoice()->map(fn ($q) => [
                'text' => $q->text,
                'correct' => $q->options->where('is_correct', true)->pluck('text')->values()->all(),
                'chosen' => $q->options->whereIn('id', $answers[$q->id]['option_ids'] ?? [])->pluck('text')->values()->all(),
                'ok' => ($score['points'][$q->id] ?? 0) > 0,
            ])->values()->all();
        }

        return $out;
    }
}
