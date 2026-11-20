<?php

namespace App\Pixelforge;

use RuntimeException;

class PixelforgeException extends RuntimeException
{
    /** Error codes where the request created nothing and trying again later can succeed. */
    private const RETRYABLE_CODES = ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'];

    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /** Network failure or timeout: we don't know whether the request reached Pixelforge. */
    public static function connection(string $message): self
    {
        return new self("Could not reach Pixelforge: {$message}", 'connection_error');
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE_CODES, true)
            || $this->errorCode === 'connection_error'
            || ($this->status !== null && $this->status >= 500);
    }
}
