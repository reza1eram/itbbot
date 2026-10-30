<?php

namespace App\Modules\AnonymousExam\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    public const TYPES = ['single', 'multi', 'true_false', 'short_text', 'long_text', 'rating', 'number'];
    public const CHOICE_TYPES = ['single', 'multi', 'true_false'];
    public const TEXT_TYPES = ['short_text', 'long_text'];

    protected $table = 'ax_questions';

    protected $fillable = ['exam_id', 'position', 'type', 'text', 'help_text', 'is_required', 'points', 'config', 'image_path'];

    protected $casts = [
        'is_required' => 'boolean',
        'points' => 'float',
        'config' => 'array',
        'position' => 'integer',
        'exam_id' => 'integer',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(Option::class, 'question_id')->orderBy('position')->orderBy('id');
    }

    public function isChoice(): bool
    {
        return in_array($this->type, self::CHOICE_TYPES, true);
    }

    public function isText(): bool
    {
        return in_array($this->type, self::TEXT_TYPES, true);
    }

    public function maxLength(): int
    {
        $default = $this->type === 'short_text' ? 300 : 3000;

        return max(1, (int) ($this->config['max_length'] ?? $default));
    }

    /** @return array{0:int,1:int} */
    public function ratingRange(): array
    {
        return [(int) ($this->config['min'] ?? 1), (int) ($this->config['max'] ?? 5)];
    }

    /** @return array{0:?float,1:?float} */
    public function numberRange(): array
    {
        $min = $this->config['min'] ?? null;
        $max = $this->config['max'] ?? null;

        return [$min !== null && $min !== '' ? (float) $min : null, $max !== null && $max !== '' ? (float) $max : null];
    }
}
