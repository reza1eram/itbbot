<?php

namespace App\Modules\AnonymousExam\Support;

final class AccessResult
{
    public const OK = 'ok';
    public const NOT_STARTED = 'not_started';
    public const ENDED = 'ended';
    public const NOT_MEMBER = 'not_member';
    public const UNKNOWN_MEMBERSHIP = 'membership_unverifiable';
    public const CALLBACK_REQUIRED = 'callback_required';
    public const ALREADY_SUBMITTED = 'already_submitted';
    public const NOT_FOUND = 'not_found';

    private function __construct(public readonly string $status, public readonly ?string $url = null)
    {
    }

    public static function ok(string $url): self
    {
        return new self(self::OK, $url);
    }

    public static function fail(string $status): self
    {
        return new self($status);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }
}
