<?php

namespace App\Modules\AnonymousExam\Services;

use App\Modules\AnonymousExam\Bale\BaleApi;
use App\Modules\AnonymousExam\Bale\BaleApiException;
use App\Modules\AnonymousExam\Models\Exam;
use App\Modules\AnonymousExam\Models\Group;
use App\Modules\AnonymousExam\Models\GroupMember;
use App\Modules\AnonymousExam\Support\VoterHasher;
use Illuminate\Support\Facades\Cache;

/**
 * BALE_MEMBERSHIP_MODE:
 *  - getchatmember: ask Bale (cached 60s); a fresh in-group callback tap also counts as proof
 *  - callback:      only a tap on the callback button inside the group proves membership
 *  - manual:        admin-provided list of ids, stored as HMACs
 */
class MembershipVerifierImpl implements MembershipVerifier
{
    private const MEMBER_STATUSES = ['creator', 'administrator', 'member', 'restricted'];
    private const PROOF_TTL_SECONDS = 900;

    public function __construct(
        private readonly BaleApi $bale,
        private readonly VoterHasher $hasher,
        private readonly string $mode,
        private readonly int $cacheSeconds = 60,
    ) {
    }

    public function verify(Group $group, int|string $userId, Exam $exam): string
    {
        if (!$group->is_active) {
            return self::NOT_MEMBER;
        }

        $hasProof = Cache::has($this->hasher->callbackProofKey($userId, $exam->id));

        return match ($this->mode) {
            'callback' => $hasProof ? self::MEMBER : self::CALLBACK_REQUIRED,
            'manual' => $this->verifyManual($group, $userId),
            default => $hasProof ? self::MEMBER : $this->verifyViaApi($group, $userId),
        };
    }

    public function rememberCallbackProof(int|string $userId, Exam $exam): void
    {
        Cache::put($this->hasher->callbackProofKey($userId, $exam->id), 1, self::PROOF_TTL_SECONDS);
    }

    private function verifyManual(Group $group, int|string $userId): string
    {
        $exists = GroupMember::query()
            ->where('group_id', $group->id)
            ->where('member_hash', $this->hasher->memberHash($userId, $group->id))
            ->exists();

        return $exists ? self::MEMBER : self::NOT_MEMBER;
    }

    private function verifyViaApi(Group $group, int|string $userId): string
    {
        $key = $this->hasher->membershipCacheKey($userId, $group->chat_id);
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return $cached;
        }

        try {
            $member = $this->bale->getChatMember($group->chat_id, $userId);
            $status = (string) ($member['status'] ?? '');
            $result = in_array($status, self::MEMBER_STATUSES, true) ? self::MEMBER : self::NOT_MEMBER;
            // "restricted" users who already left the group report is_member=false (Telegram semantics)
            if ($status === 'restricted' && array_key_exists('is_member', $member) && !$member['is_member']) {
                $result = self::NOT_MEMBER;
            }
        } catch (BaleApiException $e) {
            // 400/404 = user not in chat / not found. 403 usually means the BOT lacks access,
            // so it is treated (like network errors) as "cannot verify", never as a membership decision.
            if (in_array($e->errorCode, [400, 404], true)) {
                $result = self::NOT_MEMBER;
            } else {
                return self::UNKNOWN;
            }
        }

        Cache::put($key, $result, $this->cacheSeconds);

        return $result;
    }
}
