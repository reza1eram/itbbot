<?php

namespace App\Modules\AnonymousExam\Services;

use App\Modules\AnonymousExam\Models\AdminUser;
use App\Modules\AnonymousExam\Models\Exam;
use App\Modules\AnonymousExam\Models\Question;
use App\Modules\AnonymousExam\Support\Display;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ExamBuilderService
{
    /** Fields that may still change after the exam has been opened */
    public const SAFE_WHEN_LOCKED = ['title', 'description', 'ends_at', 'reminders', 'announce_on_close'];

    private const CODE_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function __construct(private readonly AuditService $audit, private readonly SettingsService $settings)
    {
    }

    public function create(array $data, AdminUser $admin): Exam
    {
        return DB::transaction(function () use ($data, $admin) {
            $exam = new Exam([
                'public_code' => $this->newPublicCode(),
                'status' => Exam::STATUS_DRAFT,
                'created_by_admin_id' => $admin->id,
                'min_responses_to_show' => $this->settings->defaultMinResponses(),
                'reminders' => $this->settings->defaultReminders(),
            ]);
            $this->fill($exam, $data, false);
            $exam->save();
            $this->syncQuestions($exam, $data['questions'] ?? []);
            $this->audit->log($admin, 'exam.create', $exam, ['title' => $exam->title]);

            return $exam;
        });
    }

    public function update(Exam $exam, array $data, AdminUser $admin): Exam
    {
        if ($exam->status === Exam::STATUS_ARCHIVED) {
            throw new RuntimeException('آزمون بایگانی‌شده قابل ویرایش نیست.');
        }

        return DB::transaction(function () use ($exam, $data, $admin) {
            $locked = $exam->isStructureLocked();
            $before = $exam->only(self::SAFE_WHEN_LOCKED);
            $this->fill($exam, $data, $locked);

            if ($locked && $exam->ends_at && $exam->ends_at->isPast() && $exam->isOpen()) {
                throw new RuntimeException('زمان پایان جدید باید در آینده باشد.');
            }
            $exam->save();

            if (!$locked && array_key_exists('questions', $data)) {
                $this->syncQuestions($exam, $data['questions'] ?? []);
            }

            $changed = array_keys(array_diff_assoc(
                array_map(fn ($v) => json_encode($v), $exam->only(self::SAFE_WHEN_LOCKED)),
                array_map(fn ($v) => json_encode($v), $before)
            ));
            $this->audit->log($admin, $locked ? 'exam.update_locked' : 'exam.update', $exam, ['fields' => $locked ? $changed : 'all']);

            return $exam;
        });
    }

    public function duplicate(Exam $source, AdminUser $admin): Exam
    {
        return DB::transaction(function () use ($source, $admin) {
            $copy = $source->replicate([
                'public_code', 'status', 'opened_at', 'closed_at', 'closed_by_admin_id', 'announce_message_id', 'reminders_sent', 'starts_at', 'ends_at',
            ]);
            $copy->public_code = $this->newPublicCode();
            $copy->status = Exam::STATUS_DRAFT;
            $copy->title = Str::limit($source->title . ' (کپی)', 250, '');
            $copy->created_by_admin_id = $admin->id;
            $copy->save();

            foreach ($source->questions()->with('options')->get() as $q) {
                $newQ = $q->replicate(['exam_id']);
                $newQ->exam_id = $copy->id;
                $newQ->save();
                foreach ($q->options as $o) {
                    $newO = $o->replicate(['question_id']);
                    $newO->question_id = $newQ->id;
                    $newO->save();
                }
            }
            $this->audit->log($admin, 'exam.duplicate', $copy, ['from' => $source->id]);

            return $copy;
        });
    }

    /** JSON shape used by the builder UI */
    public function toPayload(Exam $exam): array
    {
        return [
            'id' => $exam->id,
            'public_code' => $exam->public_code,
            'status' => $exam->status,
            'locked' => $exam->isStructureLocked(),
            'title' => $exam->title,
            'description' => $exam->description,
            'group_id' => $exam->group_id,
            'mode' => $exam->mode,
            'starts_at' => Display::inputValue($exam->starts_at),
            'ends_at' => Display::inputValue($exam->ends_at),
            'duration_minutes' => $exam->duration_minutes,
            'shuffle_questions' => $exam->shuffle_questions,
            'shuffle_options' => $exam->shuffle_options,
            'display_mode' => $exam->display_mode,
            'show_result_to_voter' => $exam->show_result_to_voter,
            'results_visibility' => $exam->results_visibility,
            'min_responses_to_show' => $exam->min_responses_to_show,
            'announce_on_open' => $exam->announce_on_open,
            'announce_on_close' => $exam->announce_on_close,
            'reminders' => implode(', ', (array) $exam->reminders),
            'questions' => $exam->questions()->with('options')->get()->map(fn (Question $q) => [
                'id' => $q->id,
                'type' => $q->type,
                'text' => $q->text,
                'help_text' => $q->help_text,
                'is_required' => $q->is_required,
                'points' => $q->points,
                'config' => $q->config ?? (object) [],
                'options' => $q->options->map(fn ($o) => ['text' => $o->text, 'is_correct' => $o->is_correct])->values(),
            ])->values(),
        ];
    }

    /**
     * Bulk import. Plain text format (blocks separated by an empty line):
     *   [multi] متن سؤال           <- optional type tag: [single] [multi] [tf] [short] [long] [rating] [number]
     *   - گزینه
     *   * گزینهٔ صحیح
     * CSV format (header optional): type,text,options (| separated),correct (1-based, | separated),points,required
     */
    public function parseImport(string $raw, string $format = 'text'): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", trim($raw));
        if ($raw === '') {
            return [];
        }

        return $format === 'csv' ? $this->parseCsv($raw) : $this->parseText($raw);
    }

    private function parseText(string $raw): array
    {
        $tags = ['single' => 'single', 'multi' => 'multi', 'tf' => 'true_false', 'short' => 'short_text', 'long' => 'long_text', 'rating' => 'rating', 'number' => 'number'];
        $out = [];
        foreach (preg_split("/\n\s*\n/u", $raw) as $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn ($l) => $l !== ''));
            if (!$lines) {
                continue;
            }
            $first = array_shift($lines);
            $type = null;
            if (preg_match('/^\[(\w+)\]\s*(.*)$/u', $first, $m) && isset($tags[strtolower($m[1])])) {
                $type = $tags[strtolower($m[1])];
                $first = $m[2];
            }
            $options = [];
            foreach ($lines as $line) {
                if (preg_match('/^([-*•])\s*(.+)$/u', $line, $m)) {
                    $options[] = ['text' => mb_substr($m[2], 0, 1000), 'is_correct' => $m[1] === '*'];
                }
            }
            $type ??= $options ? (count(array_filter($options, fn ($o) => $o['is_correct'])) > 1 ? 'multi' : 'single') : 'short_text';
            $out[] = $this->normalizeImported($type, $first, $options, 1, true);
        }

        return $out;
    }

    private function parseCsv(string $raw): array
    {
        $out = [];
        $types = array_merge(Question::TYPES, ['tf' => 'true_false']);
        foreach (explode("\n", $raw) as $i => $line) {
            $cols = str_getcsv($line);
            if (count($cols) < 2) {
                continue;
            }
            $type = strtolower(trim($cols[0]));
            if ($i === 0 && $type === 'type') {
                continue; // header
            }
            $type = $type === 'tf' ? 'true_false' : $type;
            if (!in_array($type, $types, true)) {
                continue;
            }
            $optionTexts = array_values(array_filter(array_map('trim', explode('|', $cols[2] ?? '')), fn ($t) => $t !== ''));
            $correct = array_map('intval', array_filter(explode('|', $cols[3] ?? '')));
            $options = [];
            foreach ($optionTexts as $k => $text) {
                $options[] = ['text' => mb_substr($text, 0, 1000), 'is_correct' => in_array($k + 1, $correct, true)];
            }
            $required = !isset($cols[5]) || !in_array(strtolower(trim($cols[5])), ['0', 'no', 'false', 'خیر'], true);
            $out[] = $this->normalizeImported($type, trim($cols[1]), $options, (float) ($cols[4] ?? 1), $required);
        }

        return $out;
    }

    private function normalizeImported(string $type, string $text, array $options, float $points, bool $required): array
    {
        if ($type === 'true_false' && count($options) !== 2) {
            $options = [['text' => 'درست', 'is_correct' => true], ['text' => 'نادرست', 'is_correct' => false]];
        }
        if (!in_array($type, Question::CHOICE_TYPES, true)) {
            $options = [];
        }

        return [
            'type' => $type,
            'text' => mb_substr($text, 0, 2000),
            'help_text' => null,
            'is_required' => $required,
            'points' => $points > 0 ? $points : 1,
            'config' => $type === 'rating' ? ['min' => 1, 'max' => 5] : (object) [],
            'options' => $options,
        ];
    }

    private function fill(Exam $exam, array $data, bool $locked): void
    {
        $allowed = $locked ? self::SAFE_WHEN_LOCKED : array_keys($data);

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            switch ($key) {
                case 'starts_at':
                case 'ends_at':
                    $exam->{$key} = $value ? Carbon::parse($value, Display::tz())->utc() : null;
                    break;
                case 'reminders':
                    $exam->reminders = SettingsService::parseReminders($value);
                    break;
                case 'questions':
                    break;
                default:
                    if (in_array($key, $exam->getFillable(), true)) {
                        $exam->{$key} = $value;
                    }
            }
        }
        if (!$exam->min_responses_to_show) {
            $exam->min_responses_to_show = $this->settings->defaultMinResponses();
        }
    }

    private function syncQuestions(Exam $exam, array $questions): void
    {
        // Safe: questions can only be replaced while no participant can have answered (exam never opened)
        $exam->questions()->delete();

        foreach (array_values($questions) as $pos => $q) {
            $type = $q['type'];
            $config = array_filter((array) ($q['config'] ?? []), fn ($v) => $v !== null && $v !== '');
            if ($type === 'rating') {
                $config = ['min' => (int) ($config['min'] ?? 1), 'max' => (int) ($config['max'] ?? 5)];
                if ($config['max'] <= $config['min']) {
                    $config['max'] = $config['min'] + 1;
                }
            }

            $question = $exam->questions()->create([
                'position' => $pos,
                'type' => $type,
                'text' => $q['text'],
                'help_text' => $q['help_text'] ?? null,
                'is_required' => (bool) ($q['is_required'] ?? true),
                'points' => (float) ($q['points'] ?? 1),
                'config' => $config ?: null,
            ]);

            $options = $q['options'] ?? [];
            if ($type === 'true_false' && count($options) !== 2) {
                $options = [['text' => 'درست', 'is_correct' => true], ['text' => 'نادرست', 'is_correct' => false]];
            }
            if (in_array($type, Question::CHOICE_TYPES, true)) {
                $hasCorrect = false;
                foreach (array_values($options) as $opos => $o) {
                    $isCorrect = (bool) ($o['is_correct'] ?? false);
                    // single / true_false: at most one correct option
                    if ($type !== 'multi' && $isCorrect && $hasCorrect) {
                        $isCorrect = false;
                    }
                    $hasCorrect = $hasCorrect || $isCorrect;
                    $question->options()->create(['position' => $opos, 'text' => $o['text'], 'is_correct' => $isCorrect]);
                }
            }
        }
    }

    private function newPublicCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (Exam::query()->where('public_code', $code)->exists());

        return $code;
    }
}
