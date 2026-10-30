<?php

namespace App\Modules\AnonymousExam\Models;

use Illuminate\Database\Eloquent\Model;

/** Encrypted, not yet flushed response (ANON_POOL_MODE). Payload is encrypted with APP_KEY. */
class PoolEntry extends Model
{
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';

    protected $table = 'ax_response_pool';

    protected $fillable = ['id', 'exam_id', 'payload'];
}
