<?php

namespace App\Modules\AnonymousExam\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RESPONSE WORLD. Random UUID v4 key, hour-rounded time, no link to any identity.
 */
class Response extends Model
{
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';

    protected $table = 'ax_responses';

    protected $fillable = ['id', 'exam_id', 'submitted_hour', 'score', 'max_score'];

    protected $casts = ['submitted_hour' => 'datetime', 'score' => 'float', 'max_score' => 'float', 'exam_id' => 'integer'];

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class, 'response_id');
    }
}
