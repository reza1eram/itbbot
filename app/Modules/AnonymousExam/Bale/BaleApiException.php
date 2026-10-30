<?php

namespace App\Modules\AnonymousExam\Bale;

use RuntimeException;

class BaleApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $method = '',
        public readonly ?int $errorCode = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $errorCode ?? 0);
    }

    public function isRateLimited(): bool
    {
        return $this->errorCode === 429;
    }
}
