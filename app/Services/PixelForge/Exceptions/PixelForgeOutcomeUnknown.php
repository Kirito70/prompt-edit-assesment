<?php

namespace App\Services\PixelForge\Exceptions;

/**
 * We cannot tell whether Pixelforge created the generation (timeout,
 * connection error, 5xx, unreadable response). It is safe to retry with the
 * same idempotency key: Pixelforge returns the existing generation instead of
 * creating a second one.
 */
final class PixelForgeOutcomeUnknown extends PixelForgeException {}
