<?php

namespace App\Modules\AnonymousExam\Services;

use App\Modules\AnonymousExam\Models\Exam;
use App\Modules\AnonymousExam\Models\Group;

interface MembershipVerifier
{
    public const MEMBER = 'member';
    public const NOT_MEMBER = 'not_member';
    public const UNKNOWN = 'unknown';
    public const CALLBACK_REQUIRED = 'callback_required';

    /** One of the constants above */
    public function verify(Group $group, int|string $userId, Exam $exam): string;

    /** Record that the user tapped the join button inside the group chat (callback mode proof) */
    public function rememberCallbackProof(int|string $userId, Exam $exam): void;
}
