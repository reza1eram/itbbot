<?php

namespace App\Modules\AnonymousExam\Models;

use Illuminate\Database\Eloquent\Model;

/** Simple bot record; the token itself is only ever read from .env */
class AxBot extends Model
{
    protected $table = 'ax_bots';

    protected $fillable = ['bot_id', 'username', 'update_mode', 'status', 'get_me', 'checked_at'];

    protected $casts = ['get_me' => 'array', 'checked_at' => 'datetime'];
}
