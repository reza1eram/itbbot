<?php

namespace App\Modules\AnonymousExam\Services;

use App\Modules\AnonymousExam\Models\Exam;
use App\Modules\AnonymousExam\Models\Question;
use App\Modules\AnonymousExam\Support\Display;

/**
 * Server-side validation of a participant's answers against THIS exam's questions and options.
 * Returns normalized answers, or per-question error codes.
 */
class AnswerValidator
{
    /**
     * @param array<int|string,mixed> $input  question_id => value
     * @return array{errors: array<int|string,string>, answers: array<int,array>}
     */
    public function validate(Exam $exam, array $input): array
    {
        $questions = $exam->questions()->with('options')->get()->keyBy('id');
        $errors = [];
        $answers = [];

        foreach (array_keys($input) as $qid) {
            if (!ctype_digit((string) $qid) || !$questions->has((int) $qid)) {
                $errors[(string) $qid] = 'invalid_question';
            }
        }

        /** @var Question $question */
        foreach ($questions as $question) {
            $raw = $input[$question->id] ?? $input[(string) $question->id] ?? null;
            [$error, $normalized] = $this->validateOne($question, $raw);
            if ($error) {
                $errors[$question->id] = $error;
            } elseif ($normalized !== null) {
                $answers[$question->id] = $normalized;
            }
        }

        return ['errors' => $errors, 'answers' => $answers];
    }

    /** @return array{0:?string,1:?array} */
    private function validateOne(Question $q, mixed $raw): array
    {
        $empty = $raw === null || $raw === '' || $raw === [];
        if ($empty) {
            return [$q->is_required ? 'required' : null, null];
        }

        $optionIds = $q->options->pluck('id')->map(fn ($id) => (int) $id)->all();

        switch ($q->type) {
            case 'single':
            case 'true_false':
                if (is_array($raw) || !ctype_digit((string) $raw) || !in_array((int) $raw, $optionIds, true)) {
                    return ['invalid_option', null];
                }

                return [null, ['option_ids' => [(int) $raw]]];

            case 'multi':
                if (!is_array($raw)) {
                    return ['invalid_option', null];
                }
                $ids = [];
                foreach ($raw as $v) {
                    if (is_array($v) || !ctype_digit((string) $v) || !in_array((int) $v, $optionIds, true)) {
                        return ['invalid_option', null];
                    }
                    $ids[] = (int) $v;
                }
                $ids = array_values(array_unique($ids));
                sort($ids);

                return [null, ['option_ids' => $ids]];

            case 'short_text':
            case 'long_text':
                if (!is_string($raw)) {
                    return ['invalid_text', null];
                }
                $text = trim(str_replace("\0", '', $raw));
                if ($q->type === 'short_text') {
                    $text = preg_replace('/\s+/u', ' ', $text);
                }
                if ($text === '') {
                    return [$q->is_required ? 'required' : null, null];
                }
                if (mb_strlen($text) > $q->maxLength()) {
                    return ['too_long', null];
                }

                return [null, ['text_value' => $text]];

            case 'rating':
                $value = Display::latinDigits(is_scalar($raw) ? (string) $raw : '');
                [$min, $max] = $q->ratingRange();
                if (!preg_match('/^-?\d+$/', $value) || (int) $value < $min || (int) $value > $max) {
                    return ['out_of_range', null];
                }

                return [null, ['number_value' => (int) $value]];

            case 'number':
                $value = Display::latinDigits(is_scalar($raw) ? str_replace('٫', '.', (string) $raw) : '');
                if (!is_numeric($value) || abs((float) $value) > 1e10) {
                    return ['not_a_number', null];
                }
                [$min, $max] = $q->numberRange();
                if (($min !== null && (float) $value < $min) || ($max !== null && (float) $value > $max)) {
                    return ['out_of_range', null];
                }

                return [null, ['number_value' => (float) $value]];
        }

        return ['invalid_question', null];
    }
}
