<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The user's available balance does not cover a new generation.
 */
final class InsufficientCredits extends RuntimeException
{
    public function __construct(
        public readonly string $available,
        public readonly string $required,
    ) {
        parent::__construct("Available credits {$available} do not cover {$required}.");
    }
}
