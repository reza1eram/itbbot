<?php

namespace App\Modules\AnonymousExam\Services;

use App\Modules\AnonymousExam\Models\Answer;
use App\Modules\AnonymousExam\Models\Exam;
use App\Modules\AnonymousExam\Models\Participation;
use App\Modules\AnonymousExam\Models\PoolEntry;
use App\Modules\AnonymousExam\Models\Response;

/**
 * Aggregated results. Small-group rules:
 *  - details (distributions, texts, score stats) only when responses >= min_responses_to_show
 *  - with results_visibility=after_close, details stay locked until the exam is closed
 *  - free-text answers and CSV rows are returned in random order, without timestamps
 */
class ResultsService
{
    public function counts(Exam $exam): array
    {
        $states = Participation::query()
            ->where('exam_id', $exam->id)
            ->selectRaw('state, COUNT(*) as c')
            ->groupBy('state')
            ->pluck('c', 'state');

        $submitted = (int) ($states[Participation::STATE_SUBMITTED] ?? 0);
        $members = $exam->group?->members_count;

        return [
            'submitted' => $submitted,
            'in_progress' => (int) ($states[Participation::STATE_STARTED] ?? 0) + (int) ($states[Participation::STATE_TOKEN_ISSUED] ?? 0),
            'responses' => Response::query()->where('exam_id', $exam->id)->count(),
            'pooled' => PoolEntry::query()->where('exam_id', $exam->id)->count(),
            'members' => $members,
            'rate' => $members ? round($submitted * 100 / $members, 1) : null,
        ];
    }

    /** @return array{visible:bool,reason:?string} */
    public function visibility(Exam $exam, ?int $responses = null): array
    {
        $responses ??= Response::query()->where('exam_id', $exam->id)->count();

        if ($exam->results_visibility !== 'live' && !$exam->isClosed()) {
            return ['visible' => false, 'reason' => 'locked_until_close'];
        }
        if ($responses < max(1, (int) $exam->min_responses_to_show)) {
            return ['visible' => false, 'reason' => 'below_minimum'];
        }

        return ['visible' => true, 'reason' => null];
    }

    public function summary(Exam $exam): array
    {
        $counts = $this->counts($exam);
        $visibility = $this->visibility($exam, $counts['responses']);

        $out = ['counts' => $counts, 'visibility' => $visibility, 'min' => (int) $exam->min_responses_to_show];
        if (!$visibility['visible']) {
            return $out;
        }

        $out['scores'] = $exam->isQuiz() ? $this->scoreStats($exam) : null;
        $out['questions'] = $this->questionStats($exam, $counts['responses']);

        return $out;
    }

    /** Short text for the group close message / admin bot command (respects the rules) */
    public function scoreLine(Exam $exam): ?array
    {
        if (!$exam->isQuiz() || !$this->visibility($exam)['visible']) {
            return null;
        }
        $stats = $this->scoreStats($exam);

        return $stats['count'] ? ['avg' => $stats['avg'], 'max' => $stats['max_score']] : null;
    }

    private function scoreStats(Exam $exam): array
    {
        $rows = Response::query()->where('exam_id', $exam->id)->get(['score', 'max_score']);
        $scores = $rows->pluck('score')->filter(fn ($s) => $s !== null)->map(fn ($s) => (float) $s)->sort()->values();
        $maxScore = (float) ($rows->max('max_score') ?? 0);
        $n = $scores->count();

        $median = null;
        if ($n) {
            $mid = intdiv($n, 2);
            $median = $n % 2 ? $scores[$mid] : ($scores[$mid - 1] + $scores[$mid]) / 2;
        }

        // 10 equal bins over [0, max]
        $bins = [];
        $binCount = 10;
        for ($i = 0; $i < $binCount; $i++) {
            $bins[$i] = 0;
        }
        foreach ($scores as $s) {
            $idx = $maxScore > 0 ? (int) floor(($s / $maxScore) * $binCount) : 0;
            $bins[min($binCount - 1, max(0, $idx))]++;
        }
        $labels = [];
        for ($i = 0; $i < $binCount; $i++) {
            $labels[] = round($maxScore * $i / $binCount, 1) . '–' . round($maxScore * ($i + 1) / $binCount, 1);
        }

        return [
            'count' => $n,
            'avg' => $n ? round($scores->avg(), 2) : null,
            'median' => $median !== null ? round($median, 2) : null,
            'min' => $n ? $scores->first() : null,
            'max' => $n ? $scores->last() : null,
            'max_score' => $maxScore,
            'histogram' => ['labels' => $labels, 'values' => array_values($bins)],
        ];
    }

    private function questionStats(Exam $exam, int $responses): array
    {
        $questions = $exam->questions()->with('options')->get();
        $answers = Answer::query()
            ->whereIn('question_id', $questions->pluck('id'))
            ->get(['question_id', 'option_ids', 'text_value', 'number_value'])
            ->groupBy('question_id');

        $out = [];
        foreach ($questions as $q) {
            $list = $answers->get($q->id, collect());
            $item = ['id' => $q->id, 'text' => $q->text, 'type' => $q->type, 'answered' => $list->count(), 'responses' => $responses];

            if ($q->isChoice()) {
                $counts = array_fill_keys($q->options->pluck('id')->all(), 0);
                foreach ($list as $a) {
                    foreach ((array) $a->option_ids as $oid) {
                        if (array_key_exists($oid, $counts)) {
                            $counts[$oid]++;
                        }
                    }
                }
                $item['options'] = $q->options->map(fn ($o) => [
                    'text' => $o->text,
                    'count' => $counts[$o->id] ?? 0,
                    'correct' => $exam->isQuiz() ? $o->is_correct : null,
                ])->values()->all();
            } elseif ($q->isText()) {
                // Random order, never submission order
                $item['texts'] = $list->pluck('text_value')->filter()->shuffle()->values()->all();
            } else {
                $values = $list->pluck('number_value')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);
                $item['avg'] = $values->count() ? round($values->avg(), 2) : null;
                if ($q->type === 'rating') {
                    [$min, $max] = $q->ratingRange();
                    $dist = [];
                    for ($i = $min; $i <= $max; $i++) {
                        $dist[(string) $i] = $values->filter(fn ($v) => (int) $v === $i)->count();
                    }
                    $item['distribution'] = $dist;
                } else {
                    $item['min'] = $values->min();
                    $item['max'] = $values->max();
                }
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * CSV rows (header first). Rows are shuffled and contain no time information.
     * @return array<int,array<int,string>>|null null when results are not visible
     */
    public function csvRows(Exam $exam): ?array
    {
        if (!$this->visibility($exam)['visible']) {
            return null;
        }

        $questions = $exam->questions()->with('options')->get();
        $header = [];
        if ($exam->isQuiz()) {
            $header[] = 'نمره';
            $header[] = 'از';
        }
        foreach ($questions as $i => $q) {
            $header[] = ($i + 1) . '. ' . mb_substr($q->text, 0, 80);
        }

        $optionText = [];
        foreach ($questions as $q) {
            foreach ($q->options as $o) {
                $optionText[$o->id] = $o->text;
            }
        }

        $responses = Response::query()->where('exam_id', $exam->id)->with('answers')->get()->shuffle();
        $rows = [$header];
        foreach ($responses as $r) {
            $byQ = $r->answers->keyBy('question_id');
            $row = [];
            if ($exam->isQuiz()) {
                $row[] = (string) $r->score;
                $row[] = (string) $r->max_score;
            }
            foreach ($questions as $q) {
                $a = $byQ->get($q->id);
                if (!$a) {
                    $row[] = '';
                } elseif ($q->isChoice()) {
                    $row[] = implode(' | ', array_map(fn ($id) => $optionText[$id] ?? '', (array) $a->option_ids));
                } elseif ($q->isText()) {
                    $row[] = (string) $a->text_value;
                } else {
                    $row[] = $a->number_value !== null ? rtrim(rtrim(number_format($a->number_value, 4, '.', ''), '0'), '.') : '';
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
