<?php

namespace App\Modules\AnonymousExam\Http\Controllers\Participant;

use App\Http\Controllers\Controller;
use App\Modules\AnonymousExam\Models\Exam;
use App\Modules\AnonymousExam\Services\SettingsService;
use App\Modules\AnonymousExam\Services\SubmissionService;
use App\Modules\AnonymousExam\Services\TokenExchangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * Participant pages. No IP, user agent or user id is stored or logged anywhere on this path;
 * error logs contain only an error code and the exam id, never the request body.
 */
class ParticipantController extends Controller
{
    private const DONE_KEY = 'ax_done';

    public const MESSAGES = [
        'invalid' => 'این لینک معتبر نیست. لطفاً از ربات لینک جدید بگیرید.',
        'used' => 'این لینک قبلاً استفاده شده است. برای ادامه، از ربات لینک جدید بگیرید.',
        'expired' => 'اعتبار این لینک تمام شده است. لطفاً از ربات لینک جدید بگیرید.',
        'not_started' => 'این آزمون هنوز شروع نشده است.',
        'ended' => 'مهلت این آزمون به پایان رسیده است.',
        'closed' => 'مهلت این آزمون به پایان رسیده است و پاسخ جدیدی پذیرفته نمی‌شود.',
        'already_submitted' => 'شما قبلاً در این آزمون شرکت کرده‌اید.',
        'session_expired' => 'زمان جلسهٔ شما تمام شده است. لطفاً از ربات لینک جدید بگیرید.',
        'no_session' => 'برای شرکت در آزمون، از طریق ربات بله لینک اختصاصی بگیرید.',
        'time_over' => 'زمان مجاز پاسخ‌گویی شما تمام شده است.',
        'error' => 'خطایی رخ داد. لطفاً دوباره تلاش کنید.',
    ];

    public function exchange(Request $request, string $token, TokenExchangeService $exchange): RedirectResponse|Response
    {
        try {
            $result = $exchange->exchange($token);
        } catch (Throwable $e) {
            Log::error('ax exchange failed', ['error' => class_basename($e)]);

            return $this->error('error', 500);
        }

        if (!$result['ok']) {
            return $this->error($result['error'], 410);
        }

        // Fresh session id; identity now lives only in this server-side session
        $request->session()->regenerate(true);
        $request->session()->put(TokenExchangeService::SESSION_KEY, $result['payload'] + ['seed' => random_int(1, PHP_INT_MAX)]);

        return redirect()->route('ax.x.show');
    }

    public function show(Request $request): View|Response
    {
        $session = $this->session($request);
        if (is_string($session)) {
            return $this->error($session, 410);
        }

        $exam = Exam::query()->find($session['exam_id']);
        if (!$exam || !$exam->acceptsSubmissions()) {
            $this->forget($request);

            return $this->error('ended', 410);
        }

        $questions = $exam->questions()->with('options')->get();
        mt_srand((int) ($session['seed'] % PHP_INT_MAX));
        if ($exam->shuffle_questions) {
            $questions = $questions->sortBy(fn () => mt_rand())->values();
        }
        $questions = $questions->map(function ($q) use ($exam) {
            $options = $q->options->map(fn ($o) => ['id' => $o->id, 'text' => $o->text]);
            if ($exam->shuffle_options && $q->type !== 'true_false') {
                $options = $options->sortBy(fn () => mt_rand())->values();
            }

            return [
                'id' => $q->id,
                'type' => $q->type,
                'text' => $q->text,
                'help' => $q->help_text,
                'required' => $q->is_required,
                'max_length' => $q->isText() ? $q->maxLength() : null,
                'range' => $q->type === 'rating' ? $q->ratingRange() : ($q->type === 'number' ? $q->numberRange() : null),
                'options' => $options->values()->all(),
            ];
        });
        mt_srand();

        $deadline = $exam->duration_minutes ? (int) $session['started_at'] + $exam->duration_minutes * 60 : null;

        return view('ax::participant.form', [
            'exam' => $exam,
            'questions' => $questions,
            'deadline' => $deadline ? min($deadline, $exam->ends_at?->getTimestamp() ?? PHP_INT_MAX) : $exam->ends_at?->getTimestamp(),
            'hasDuration' => (bool) $exam->duration_minutes,
        ]);
    }

    public function submit(Request $request, SubmissionService $submissions): JsonResponse
    {
        $session = $this->session($request);
        if (is_string($session)) {
            return response()->json(['ok' => false, 'error' => $session, 'message' => self::MESSAGES[$session]], 410);
        }

        $answers = $request->input('answers', []);
        if (!is_array($answers) || count($answers) > 500) {
            return response()->json(['ok' => false, 'error' => 'error', 'message' => self::MESSAGES['error']], 422);
        }

        try {
            $result = $submissions->submit($session, $answers);
        } catch (Throwable $e) {
            Log::error('ax submit failed', ['exam_id' => $session['exam_id'] ?? null, 'error' => class_basename($e)]);

            return response()->json(['ok' => false, 'error' => 'error', 'message' => self::MESSAGES['error']], 500);
        }

        if (!$result['ok']) {
            if ($result['error'] === SubmissionService::ERR_VALIDATION) {
                return response()->json(['ok' => false, 'error' => 'validation', 'errors' => $result['errors']], 422);
            }
            if (in_array($result['error'], [SubmissionService::ERR_CLOSED, SubmissionService::ERR_ALREADY, SubmissionService::ERR_SESSION], true)) {
                $this->forget($request);
            }

            return response()->json(['ok' => false, 'error' => $result['error'], 'message' => self::MESSAGES[$result['error']] ?? self::MESSAGES['error']], 409);
        }

        // Identity is dropped from the session and the session id is rotated (old session destroyed)
        $this->forget($request);
        $request->session()->flash(self::DONE_KEY, $result['result']);

        return response()->json(['ok' => true, 'redirect' => route('ax.x.done')]);
    }

    public function done(Request $request): View
    {
        return view('ax::participant.done', ['result' => $request->session()->get(self::DONE_KEY)]);
    }

    public function privacy(SettingsService $settings): View
    {
        return view('ax::participant.privacy', ['text' => $settings->privacyText()]);
    }

    /** @return array|string session payload, or an error code */
    private function session(Request $request): array|string
    {
        $payload = $request->session()->get(TokenExchangeService::SESSION_KEY);
        if (!is_array($payload)) {
            return 'no_session';
        }
        if (($payload['expires_at'] ?? 0) < now()->getTimestamp()) {
            $this->forget($request);

            return 'session_expired';
        }

        return $payload;
    }

    private function forget(Request $request): void
    {
        $request->session()->forget(TokenExchangeService::SESSION_KEY);
        $request->session()->regenerate(true);
    }

    private function error(string $code, int $status): Response
    {
        return response()->view('ax::participant.error', ['message' => self::MESSAGES[$code] ?? self::MESSAGES['error'], 'code' => $code], $status);
    }
}
