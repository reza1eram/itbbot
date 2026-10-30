<?php

namespace App\Modules\AnonymousExam\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Actions of ADMINS only. Participant events are never recorded here. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ax_audit_logs';

    protected $fillable = ['admin_id', 'action', 'subject_type', 'subject_id', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_id');
    }
}
