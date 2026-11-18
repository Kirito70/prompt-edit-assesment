<?php

namespace App\Services\PixelForge\Exceptions;

/**
 * Pixelforge refused the request for now and created nothing (rate limit,
 * concurrency limit, overloaded). Retry later with the same idempotency key.
 */
final class PixelForgeRetryable extends PixelForgeException
{
    public function __construct(
        string $message,
        public readonly int $retryAfterSeconds,
        ?string $errorCode = null,
        ?int $httpStatus = null,
    ) {
        parent::__construct($message, $errorCode, $httpStatus);
    }
}
