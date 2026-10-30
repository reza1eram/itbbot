<?php

namespace App\Modules\AnonymousExam\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Option extends Model
{
    public $timestamps = false;

    protected $table = 'ax_options';

    protected $fillable = ['question_id', 'position', 'text', 'is_correct'];

    protected $casts = ['is_correct' => 'boolean', 'position' => 'integer', 'question_id' => 'integer'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'question_id');
    }
}
