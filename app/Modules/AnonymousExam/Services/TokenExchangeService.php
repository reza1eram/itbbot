<?php

namespace App\Modules\AnonymousExam\Services;

use App\Modules\AnonymousExam\Models\AccessToken;
use App\Modules\AnonymousExam\Models\Exam;
use App\Modules\AnonymousExam\Models\Participation;
use Illuminate\Support\Facades\DB;

/**
 * Consumes a one-time token (GET /x/{token}) and returns the payload for a short-lived server session.
 * From then on the token is dead and the identity lives only in the server-side session.
 */
class TokenExchangeService
{
    public const SESSION_KEY = 'ax_participant';

    public const ERR_INVALID = 'invalid';
    public const ERR_USED = 'used';
    public const ERR_EXPIRED = 'expired';
    public const ERR_NOT_STARTED = 'not_started';
    public const ERR_ENDED = 'ended';
    public const ERR_SUBMITTED = 'already_submitted';

    /**
     * @return array{ok:bool,error?:string,payload?:array}
     */
    public function exchange(string $plainToken): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $plainToken)) {
            return ['ok' => false, 'error' => self::ERR_INVALID];
        }

        $hash = hash('sha256', $plainToken);

        return DB::transaction(function () use ($hash) {
            $token = AccessToken::query()->whereKey($hash)->lockForUpdate()->first();
            // Comparison in constant time even though the lookup is by hash (defence in depth)
            if (!$token || !hash_equals($token->token_hash, $hash)) {
                return ['ok' => false, 'error' => self::ERR_INVALID];
            }
            if ($token->used_at || $token->revoked_at) {
                return ['ok' => false, 'error' => self::ERR_USED];
            }
            if ($token->expires_at->isPast()) {
                return ['ok' => false, 'error' => self::ERR_EXPIRED];
            }

            $exam = Exam::query()->find($token->exam_id);
            if (!$exam || $exam->isClosed() || ($exam->ends_at && now()->gte($exam->ends_at))) {
                return ['ok' => false, 'error' => self::ERR_ENDED];
            }
            if (!$exam->acceptsSubmissions()) {
                return ['ok' => false, 'error' => self::ERR_NOT_STARTED];
            }

            $participation = Participation::query()->whereKey($token->participation_id)->lockForUpdate()->first();
            if (!$participation || $participation->state === Participation::STATE_SUBMITTED) {
                return ['ok' => false, 'error' => self::ERR_SUBMITTED];
            }

            $token->used_at = now();
            $token->save();

            if ($participation->state !== Participation::STATE_STARTED) {
                $participation->state = Participation::STATE_STARTED;
                $participation->save();
            }

            $startedAt = now()->getTimestamp();
            $expires = now()->addMinutes((int) config('anonymous_exam.anon.session_ttl_minutes', 120));
            if ($exam->duration_minutes) {
                // one minute of grace for network latency on submit
                $expires = min($expires, now()->addMinutes($exam->duration_minutes + 1));
            }
            if ($exam->ends_at) {
                $expires = min($expires, $exam->ends_at->copy());
            }

            return ['ok' => true, 'payload' => [
                'exam_id' => $exam->id,
                'participation_id' => $participation->id,
                'started_at' => $startedAt,
                'expires_at' => $expires->getTimestamp(),
            ]];
        });
    }
}
