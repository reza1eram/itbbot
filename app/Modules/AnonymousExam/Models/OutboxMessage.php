<?php

namespace App\Modules\AnonymousExam\Models;

use Illuminate\Database\Eloquent\Model;

/** Outgoing GROUP messages (announce / reminder / close / test). */
class OutboxMessage extends Model
{
    protected $table = 'ax_outbox';

    protected $fillable = ['chat_id', 'exam_id', 'purpose', 'text', 'reply_markup', 'status', 'attempts', 'next_attempt_at', 'last_error', 'sent_message_id'];

    protected $casts = ['reply_markup' => 'array', 'next_attempt_at' => 'datetime', 'chat_id' => 'integer', 'attempts' => 'integer'];
}
