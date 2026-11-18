<?php

namespace App\Services\PixelForge\Exceptions;

/**
 * Pixelforge refused the request and created nothing. Retrying the same
 * request will not help (content policy, validation, reused key, bad API key,
 * or the daily limit). Mark the generation rejected; it costs nothing.
 */
final class PixelForgeRejected extends PixelForgeException {}
