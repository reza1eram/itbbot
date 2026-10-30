<?php

namespace App\Modules\AnonymousExam\Support;

use RuntimeException;

/**
 * Keyed one-way hashes for the identity world.
 * The key (ANON_HMAC_SECRET) lives only in .env; without it the hashes cannot be reversed
 * or recomputed from a list of candidate user ids.
 */
class VoterHasher
{
    public function __construct(private readonly ?string $secret)
    {
    }

    /** HMAC_SHA256(user_id . '|' . exam_id, ANON_HMAC_SECRET) */
    public function voterHash(int|string $userId, int $examId): string
    {
        return hash_hmac('sha256', $userId . '|' . $examId, $this->key());
    }

    /** For the manual membership list: HMAC of (user, group) with a distinct domain prefix */
    public function memberHash(int|string $userId, int $groupId): string
    {
        return hash_hmac('sha256', 'member|' . $userId . '|' . $groupId, $this->key());
    }

    /** Short-lived proof that a user tapped the join button inside the group (callback mode) */
    public function callbackProofKey(int|string $userId, int $examId): string
    {
        return 'ax:proof:' . hash_hmac('sha256', 'proof|' . $userId . '|' . $examId, $this->key());
    }

    /** Cache key for getChatMember results – never contains the raw id */
    public function membershipCacheKey(int|string $userId, int|string $chatId): string
    {
        return 'ax:member:' . hash_hmac('sha256', 'cache|' . $userId . '|' . $chatId, $this->key());
    }

    private function key(): string
    {
        if (!is_string($this->secret) || strlen($this->secret) < 32) {
            throw new RuntimeException('ANON_HMAC_SECRET is missing or too short (see .env)');
        }

        return $this->secret;
    }
}
