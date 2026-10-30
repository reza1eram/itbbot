<?php

namespace App\Modules\AnonymousExam\Services;

use App\Modules\AnonymousExam\Models\AccessToken;
use App\Modules\AnonymousExam\Models\Exam;
use App\Modules\AnonymousExam\Models\Participation;
use App\Modules\AnonymousExam\Support\AccessResult;
use App\Modules\AnonymousExam\Support\VoterHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * IDENTITY WORLD: decides whether a Bale user may take part and issues a one-time access link.
 * The raw user id is used only in memory; only HMACs are persisted.
 */
class AccessService
{
    public function __construct(
        private readonly MembershipVerifier $membership,
        private readonly VoterHasher $hasher,
        private readonly SettingsService $settings,
    ) {
    }

    public function availability(Exam $exam): string
    {
        if ($exam->isClosed() || ($exam->ends_at && now()->gte($exam->ends_at))) {
            return AccessResult::ENDED;
        }
        if (!$exam->isOpen() || ($exam->starts_at && now()->lt($exam->starts_at))) {
            return AccessResult::NOT_STARTED;
        }

        return AccessResult::OK;
    }

    public function requestAccess(Exam $exam, int|string $userId): AccessResult
    {
        $availability = $this->availability($exam);
        if ($availability !== AccessResult::OK) {
            return AccessResult::fail($availability);
        }

        $group = $exam->group;
        if (!$group) {
            return AccessResult::fail(AccessResult::NOT_FOUND);
        }

        $membership = $this->membership->verify($group, $userId, $exam);
        if ($membership !== MembershipVerifier::MEMBER) {
            return AccessResult::fail(match ($membership) {
                MembershipVerifier::NOT_MEMBER => AccessResult::NOT_MEMBER,
                MembershipVerifier::CALLBACK_REQUIRED => AccessResult::CALLBACK_REQUIRED,
                default => AccessResult::UNKNOWN_MEMBERSHIP,
            });
        }

        $voterHash = $this->hasher->voterHash($userId, $exam->id);

        $token = DB::transaction(function () use ($exam, $voterHash) {
            // Insert-if-missing first so that lockForUpdate always has a row to lock
            Participation::query()->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'exam_id' => $exam->id,
                'voter_hash' => $voterHash,
                'state' => Participation::STATE_TOKEN_ISSUED,
                'day' => now()->toDateString(),
            ]);

            $participation = Participation::query()
                ->where('exam_id', $exam->id)
                ->where('voter_hash', $voterHash)
                ->lockForUpdate()
                ->firstOrFail();

            if ($participation->state === Participation::STATE_SUBMITTED) {
                return null;
            }

            // A new link invalidates any earlier, unused link of the same person
            AccessToken::query()
                ->where('participation_id', $participation->id)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $plain = self::newToken();
            AccessToken::query()->create([
                'token_hash' => hash('sha256', $plain),
                'participation_id' => $participation->id,
                'exam_id' => $exam->id,
                'expires_at' => now()->addMinutes($this->settings->tokenTtlMinutes()),
            ]);

            return $plain;
        });

        if ($token === null) {
            return AccessResult::fail(AccessResult::ALREADY_SUBMITTED);
        }

        return AccessResult::ok($this->publicUrl('x/' . $token));
    }

    /** Only a yes/no answer; nothing about the content of any response */
    public function hasSubmitted(Exam $exam, int|string $userId): bool
    {
        return Participation::query()
            ->where('exam_id', $exam->id)
            ->where('voter_hash', $this->hasher->voterHash($userId, $exam->id))
            ->where('state', Participation::STATE_SUBMITTED)
            ->exists();
    }

    public function publicUrl(string $path): string
    {
        $base = (string) (config('anonymous_exam.public_url') ?: config('app.url'));

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    /** 32 random bytes, base64url */
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
