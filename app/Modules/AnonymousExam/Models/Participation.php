<?php

namespace App\Modules\AnonymousExam\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * IDENTITY WORLD. Holds only an HMAC of (user, exam) to prevent double voting.
 * Must never get a relation to Response / Answer.
 */
class Participation extends Model
{
    public const STATE_TOKEN_ISSUED = 'token_issued';
    public const STATE_STARTED = 'started';
    public const STATE_SUBMITTED = 'submitted';

    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';

    protected $table = 'ax_participations';

    protected $fillable = ['id', 'exam_id', 'voter_hash', 'state', 'day'];

    protected $casts = ['day' => 'date', 'exam_id' => 'integer'];
}
