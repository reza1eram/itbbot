<?php

namespace App\Modules\AnonymousExam\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUSES = ['draft', 'scheduled', 'open', 'closed', 'archived'];

    protected $table = 'ax_exams';

    protected $fillable = [
        'public_code', 'title', 'description', 'group_id', 'mode', 'status', 'starts_at', 'ends_at',
        'duration_minutes', 'shuffle_questions', 'shuffle_options', 'display_mode', 'show_result_to_voter',
        'results_visibility', 'min_responses_to_show', 'announce_on_open', 'announce_on_close', 'reminders',
        'reminders_sent', 'announce_message_id', 'opened_at', 'closed_at', 'closed_by_admin_id', 'created_by_admin_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'shuffle_questions' => 'boolean',
        'shuffle_options' => 'boolean',
        'announce_on_open' => 'boolean',
        'announce_on_close' => 'boolean',
        'reminders' => 'array',
        'reminders_sent' => 'array',
        'duration_minutes' => 'integer',
        'min_responses_to_show' => 'integer',
        'group_id' => 'integer',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'exam_id')->orderBy('position')->orderBy('id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_CLOSED, self::STATUS_ARCHIVED], true);
    }

    /** Structure (questions, options, mode, group) is locked once the exam has been opened */
    public function isStructureLocked(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_CLOSED, self::STATUS_ARCHIVED], true);
    }

    /** Server-side acceptance window for submissions */
    public function acceptsSubmissions(): bool
    {
        if ($this->status !== self::STATUS_OPEN) {
            return false;
        }
        $now = now();
        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }
        if ($this->ends_at && $now->gte($this->ends_at)) {
            return false;
        }

        return true;
    }

    public function isQuiz(): bool
    {
        return $this->mode === 'quiz';
    }
}
