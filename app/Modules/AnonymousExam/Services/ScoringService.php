<?php

namespace App\Modules\AnonymousExam\Services;

use App\Modules\AnonymousExam\Models\Exam;
use Illuminate\Support\Collection;

/**
 * Quiz scoring. Only choice questions (single, multi, true_false) are scored:
 *  - single / true_false: full points when the chosen option is correct
 *  - multi: full points only when the selected set equals the set of correct options
 * Survey mode: no score at all.
 */
class ScoringService
{
    /**
     * @param array<int,array> $answers normalized answers from AnswerValidator (by question id)
     * @return array{score:?float,max_score:?float,points:array<int,?float>}
     */
    public function score(Exam $exam, Collection $questions, array $answers): array
    {
        if (!$exam->isQuiz()) {
            return ['score' => null, 'max_score' => null, 'points' => []];
        }

        $score = 0.0;
        $max = 0.0;
        $points = [];

        foreach ($questions as $q) {
            if (!$q->isChoice()) {
                continue;
            }
            $worth = (float) $q->points;
            $max += $worth;

            $correct = $q->options->where('is_correct', true)->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
            $chosen = $answers[$q->id]['option_ids'] ?? [];
            sort($chosen);

            $ok = $correct !== [] && $chosen === $correct;
            if (in_array($q->type, ['single', 'true_false'], true)) {
                $ok = count($chosen) === 1 && in_array($chosen[0], $correct, true);
            }

            $awarded = $ok ? $worth : 0.0;
            $points[$q->id] = $awarded;
            $score += $awarded;
        }

        return ['score' => round($score, 2), 'max_score' => round($max, 2), 'points' => $points];
    }
}
