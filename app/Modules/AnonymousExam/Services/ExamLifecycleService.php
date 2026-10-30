<?php

namespace App\Modules\AnonymousExam\Services;

use App\Modules\AnonymousExam\Bale\BaleApi;
use App\Modules\AnonymousExam\Models\AccessToken;
use App\Modules\AnonymousExam\Models\AdminUser;
use App\Modules\AnonymousExam\Models\Exam;
use App\Modules\AnonymousExam\Support\Display;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ExamLifecycleService
{
    public function __construct(
        private readonly OutboxService $outbox,
        private readonly TemplateService $templates,
        private readonly ResultsService $results,
        private readonly AuditService $audit,
        private readonly PoolFlushService $pool,
        private readonly BaleApi $bale,
    ) {
    }

    /** Problems that prevent publishing; empty array = ready */
    public function publishProblems(Exam $exam): array
    {
        $problems = [];
        if (!$exam->group || !$exam->group->is_active) {
            $problems[] = 'گروه هدف انتخاب نشده یا غیرفعال است.';
        }
        $questions = $exam->questions()->with('options')->get();
        if ($questions->isEmpty()) {
            $problems[] = 'آزمون هیچ سؤالی ندارد.';
        }
        foreach ($questions as $i => $q) {
            if ($q->isChoice() && $q->options->count() < 2) {
                $problems[] = 'سؤال ' . Display::digits($i + 1) . ' کمتر از دو گزینه دارد.';
            }
            if ($exam->isQuiz() && $q->isChoice() && !$q->options->contains('is_correct', true)) {
                $problems[] = 'سؤال ' . Display::digits($i + 1) . ' گزینهٔ صحیح ندارد.';
            }
        }
        if (!$exam->ends_at) {
            $problems[] = 'زمان پایان تعیین نشده است.';
        } elseif ($exam->ends_at->isPast()) {
            $problems[] = 'زمان پایان گذشته است.';
        }
        if ($exam->starts_at && $exam->ends_at && $exam->starts_at->gte($exam->ends_at)) {
            $problems[] = 'زمان شروع باید قبل از زمان پایان باشد.';
        }

        return $problems;
    }

    /** draft -> scheduled (opens automatically at starts_at) */
    public function publish(Exam $exam, ?AdminUser $admin): void
    {
        $problems = $this->publishProblems($exam);
        if ($problems) {
            throw new RuntimeException(implode(' ', $problems));
        }
        if (!in_array($exam->status, [Exam::STATUS_DRAFT, Exam::STATUS_SCHEDULED], true)) {
            throw new RuntimeException('فقط آزمون پیش‌نویس قابل انتشار است.');
        }
        $exam->status = Exam::STATUS_SCHEDULED;
        $exam->save();
        $this->audit->log($admin, 'exam.publish', $exam);

        if (!$exam->starts_at || $exam->starts_at->lte(now())) {
            $this->open($exam, $admin);
        }
    }

    /** Start now (button «شروع فوری») or by the scheduler */
    public function open(Exam $exam, ?AdminUser $admin): void
    {
        $opened = DB::transaction(function () use ($exam) {
            $locked = Exam::query()->whereKey($exam->id)->lockForUpdate()->first();
            if (!$locked || $locked->isOpen() || $locked->isClosed()) {
                return false;
            }
            if ($problems = $this->publishProblems($locked)) {
                throw new RuntimeException(implode(' ', $problems));
            }
            $locked->status = Exam::STATUS_OPEN;
            $locked->opened_at = now();
            if (!$locked->starts_at || $locked->starts_at->isFuture()) {
                $locked->starts_at = now();
            }
            $locked->save();

            return true;
        });
        if (!$opened) {
            return;
        }

        $exam->refresh();
        $this->audit->log($admin, 'exam.open', $exam);
        Log::info('ax exam opened', ['exam_id' => $exam->id]);

        if ($exam->announce_on_open) {
            $this->announce($exam);
        }
    }

    public function announce(Exam $exam, ?AdminUser $admin = null): void
    {
        $group = $exam->group;
        if (!$group) {
            throw new RuntimeException('گروه هدف مشخص نیست.');
        }

        // Bale cannot edit messages that carry buttons, so an old announcement is deleted and a new one sent
        if ($exam->announce_message_id) {
            try {
                $this->bale->deleteMessage($group->chat_id, $exam->announce_message_id);
            } catch (Throwable) {
                // older than 48h or already deleted – fine
            }
        }

        $text = $this->templates->render('announce', [
            'title' => $exam->title,
            'description' => trim((string) $exam->description) ?: ' ',
            'ends_at' => Display::dateTime($exam->ends_at),
        ]);

        $this->outbox->enqueue($group->chat_id, $text, $this->joinKeyboard($exam), $exam->id, 'announce');
        if ($admin) {
            $this->audit->log($admin, 'exam.announce', $exam);
        }
    }

    public function joinKeyboard(Exam $exam): array
    {
        $mode = (string) config('anonymous_exam.bale.join_button', 'both');
        $username = (string) config('anonymous_exam.bale.username', 'ITBbot');
        $rows = [];
        if (in_array($mode, ['url', 'both'], true)) {
            $rows[] = [[
                'text' => $this->templates->render('announce_button_url'),
                'url' => 'https://ble.ir/' . $username . '?start=exam_' . $exam->public_code,
            ]];
        }
        if (in_array($mode, ['callback', 'both'], true)) {
            $rows[] = [[
                'text' => $this->templates->render('announce_button_callback'),
                'callback_data' => 'ax:join:' . $exam->public_code,
            ]];
        }

        return ['inline_keyboard' => $rows];
    }

    public function sendReminder(Exam $exam, ?int $minutes = null, ?AdminUser $admin = null): void
    {
        if (!$exam->isOpen() || !$exam->group) {
            throw new RuntimeException('یادآوری فقط برای آزمون باز ممکن است.');
        }
        $minutes ??= max(1, (int) ceil(now()->diffInSeconds($exam->ends_at, false) / 60));
        $text = $this->templates->render('reminder', [
            'title' => $exam->title,
            'minutes' => Display::digits($minutes),
            'count' => Display::digits($this->results->counts($exam)['submitted']),
        ]);
        $this->outbox->enqueue($exam->group->chat_id, $text, $this->joinKeyboard($exam), $exam->id, 'reminder');
        if ($admin) {
            $this->audit->log($admin, 'exam.remind', $exam);
        }
    }

    /** «پایان آزمون»: close, revoke open tokens, unlock results, optionally announce a summary */
    public function close(Exam $exam, ?AdminUser $admin): void
    {
        $closed = DB::transaction(function () use ($exam, $admin) {
            $locked = Exam::query()->whereKey($exam->id)->lockForUpdate()->first();
            if (!$locked || $locked->isClosed()) {
                return false;
            }
            $locked->status = Exam::STATUS_CLOSED;
            $locked->closed_at = now();
            $locked->closed_by_admin_id = $admin?->id;
            $locked->save();

            AccessToken::query()
                ->where('exam_id', $locked->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return true;
        });
        if (!$closed) {
            return;
        }

        $exam->refresh();
        // Open participant sessions die on their own: every submit re-checks the exam status under lock.
        $this->pool->flush($exam->id, true);
        $this->audit->log($admin, 'exam.close', $exam, ['by' => $admin ? 'admin' : 'scheduler']);
        Log::info('ax exam closed', ['exam_id' => $exam->id]);

        if ($exam->announce_on_close && $exam->group) {
            $text = $this->templates->render('closed', [
                'title' => $exam->title,
                'count' => Display::digits($this->results->counts($exam)['submitted']),
            ]);
            if ($line = $this->results->scoreLine($exam)) {
                $text .= "\n" . $this->templates->render('closed_score', [
                    'avg' => Display::number($line['avg'], 2),
                    'max' => Display::number($line['max'], 2),
                ]);
            }
            $this->outbox->enqueue($exam->group->chat_id, $text, null, $exam->id, 'close');
        }
    }

    public function archive(Exam $exam, ?AdminUser $admin): void
    {
        if ($exam->isOpen()) {
            throw new RuntimeException('آزمون باز را ابتدا ببندید.');
        }
        $exam->status = Exam::STATUS_ARCHIVED;
        $exam->save();
        $this->audit->log($admin, 'exam.archive', $exam);
    }

    /** Called every minute by the scheduler (exam:tick) */
    public function tick(): array
    {
        $report = ['opened' => 0, 'closed' => 0, 'reminders' => 0];

        Exam::query()->where('status', Exam::STATUS_SCHEDULED)->where('starts_at', '<=', now())->each(function (Exam $exam) use (&$report) {
            try {
                $this->open($exam, null);
                $report['opened']++;
            } catch (Throwable $e) {
                Log::error('ax tick open failed', ['exam_id' => $exam->id, 'error' => class_basename($e)]);
            }
        });

        Exam::query()->where('status', Exam::STATUS_OPEN)->whereNotNull('ends_at')->where('ends_at', '<=', now())->each(function (Exam $exam) use (&$report) {
            $this->close($exam, null);
            $report['closed']++;
        });

        Exam::query()->where('status', Exam::STATUS_OPEN)->whereNotNull('ends_at')->each(function (Exam $exam) use (&$report) {
            $sent = (array) ($exam->reminders_sent ?? []);
            $left = now()->diffInSeconds($exam->ends_at, false) / 60;
            foreach ((array) ($exam->reminders ?? []) as $minutes) {
                $minutes = (int) $minutes;
                if ($minutes <= 0 || in_array($minutes, $sent, true) || $left > $minutes || $left <= 0) {
                    continue;
                }
                // Mark first so that a failing send never spams the group on every tick
                $sent[] = $minutes;
                $exam->reminders_sent = $sent;
                $exam->save();
                // Skip reminders that would land right after opening (e.g. a 60-min reminder for a 30-min exam)
                if ($exam->opened_at && $exam->opened_at->diffInMinutes(now()) < 2) {
                    continue;
                }
                try {
                    $this->sendReminder($exam, $minutes);
                    $report['reminders']++;
                } catch (Throwable $e) {
                    Log::error('ax reminder failed', ['exam_id' => $exam->id, 'error' => class_basename($e)]);
                }
                break; // at most one reminder per exam per tick
            }
        });

        if (config('anonymous_exam.anon.pool_mode')) {
            $this->pool->flush();
        }

        return $report;
    }
}
