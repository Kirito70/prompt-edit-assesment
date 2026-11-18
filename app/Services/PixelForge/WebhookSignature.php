<?php

namespace App\Services\PixelForge;

/**
 * Verifies the `Pixelforge-Signature` header: `t=<unix timestamp>,v1=<hex>`.
 *
 * The signature is HMAC-SHA256 over "<timestamp>.<raw request body>" with the
 * API key's signing secret. It must be checked against the raw body, before
 * any JSON decoding, and compared in constant time.
 */
final class WebhookSignature
{
    /** Pixelforge's own example rejects anything further from "now" than this. */
    public const TOLERANCE_SECONDS = 300;

    public function verify(string $header, string $rawBody, string $secret, ?int $now = null): bool
    {
        // Without a secret anyone could forge a valid signature, so fail closed.
        if ($secret === '') {
            return false;
        }

        [$timestamp, $signatures] = $this->parse($header);

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(($now ?? time()) - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);

        // Several v1 values can appear while a secret is being rotated.
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: int|null, 1: list<string>}
     */
    private function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }
}
