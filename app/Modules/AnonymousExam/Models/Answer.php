<?php

namespace App\Modules\AnonymousExam\Models;

use Illuminate\Database\Eloquent\Model;

/** RESPONSE WORLD. Composite key (response_id, question_id); no auto-increment id. */
class Answer extends Model
{
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = null;

    protected $table = 'ax_answers';

    protected $fillable = ['response_id', 'question_id', 'option_ids', 'text_value', 'number_value', 'points_awarded'];

    protected $casts = ['option_ids' => 'array', 'number_value' => 'float', 'points_awarded' => 'float', 'question_id' => 'integer'];
}
