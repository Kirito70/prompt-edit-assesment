<?php

namespace App\Services\PixelForge\Exceptions;

use RuntimeException;

/**
 * Base class for every failure talking to Pixelforge. Catch one of the three
 * subclasses to decide what to do with the generation.
 */
abstract class PixelForgeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
