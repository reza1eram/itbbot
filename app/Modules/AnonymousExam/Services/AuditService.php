<?php

namespace App\Modules\AnonymousExam\Services;

use App\Modules\AnonymousExam\Models\AdminUser;
use App\Modules\AnonymousExam\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/** Records ADMIN actions only (never participant events). */
class AuditService
{
    public function log(?AdminUser $admin, string $action, ?Model $subject = null, array $meta = []): void
    {
        AuditLog::query()->create([
            'admin_id' => $admin?->id,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'meta' => $meta ?: null,
        ]);
    }
}
