<?php

namespace App\Modules\AnonymousExam\Models;

use Illuminate\Database\Eloquent\Model;

/** IDENTITY WORLD. Only sha256(token) is stored, never the token itself. */
class AccessToken extends Model
{
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = 'token_hash';
    protected $keyType = 'string';

    protected $table = 'ax_access_tokens';

    protected $fillable = ['token_hash', 'participation_id', 'exam_id', 'expires_at', 'used_at', 'revoked_at'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime', 'revoked_at' => 'datetime', 'exam_id' => 'integer'];
}
