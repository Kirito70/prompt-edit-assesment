<?php

namespace App\Services;

use App\Services\PixelForge\Exceptions\PixelForgeOutcomeUnknown;
use App\Services\PixelForge\Exceptions\PixelForgeRejected;
use App\Services\PixelForge\Exceptions\PixelForgeRetryable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Thin adapter over the Pixelforge HTTP API.
 *
 * It never retries: whoever calls it (a queued job) decides when to try again,
 * reusing the same idempotency key so a retry can never create a second
 * generation. Every failure is mapped to one of three exceptions, see
 * App\Services\PixelForge\Exceptions.
 */
final class PixelForgeService
{
    private const PRICING_CACHE_KEY = 'pixelforge.pricing';

    private const PRICING_TTL_SECONDS = 300;

    private const DEFAULT_RETRY_AFTER_SECONDS = 5;

    private const MAX_IDEMPOTENCY_KEY_LENGTH = 255;

    public function __construct(private readonly GenerationPricing $pricing) {}

    /**
     * The published pricing, cached briefly. A generation's own `rates` decide
     * what it actually costs, so a slightly stale copy only affects estimates.
     *
     * @return array<string, mixed>
     */
    public function pricing(): array
    {
        return Cache::remember(
            self::PRICING_CACHE_KEY,
            self::PRICING_TTL_SECONDS,
            fn (): array => $this->decode($this->send(fn () => $this->client()->get('/pricing'))),
        );
    }

    /**
     * What a generation is expected to cost the user, before submitting.
     *
     * @param  list<string>  $referenceImages
     * @return array<string, mixed>
     */
    public function estimate(string $prompt, string $size, string $quality, array $referenceImages): array
    {
        return $this->pricing->estimate($prompt, $size, $quality, $referenceImages, $this->pricing());
    }

    /**
     * Submit a generation. Pass the same $idempotencyKey and the same
     * arguments on every retry.
     *
     * @param  list<string>  $referenceImages
     * @param  array<string, string>  $metadata
     * @return array<string, mixed> the Pixelforge generation object
     */
    public function createGeneration(
        string $prompt,
        string $size,
        string $quality,
        array $referenceImages,
        string $idempotencyKey,
        array $metadata = [],
    ): array {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > self::MAX_IDEMPOTENCY_KEY_LENGTH) {
            throw new InvalidArgumentException('Idempotency key must be 1–255 characters.');
        }

        $body = [
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'reference_images' => $referenceImages,
        ];

        if ($metadata !== []) {
            $body['metadata'] = $metadata;
        }

        $generation = $this->decode($this->send(
            fn () => $this->client()
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post('/generations', $body),
        ));

        if (! is_string($generation['id'] ?? null)) {
            throw new PixelForgeOutcomeUnknown('Pixelforge response had no generation id.');
        }

        return $generation;
    }

    /**
     * @return array<string, mixed> the Pixelforge generation object
     */
    public function getGeneration(string $providerGenerationId): array
    {
        return $this->decode($this->send(
            fn () => $this->client()->get('/generations/'.rawurlencode($providerGenerationId)),
        ));
    }

    /**
     * What Pixelforge says it billed this API key per UTC day, for reconciliation.
     *
     * @return array<string, mixed>
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->decode($this->send(
            fn () => $this->client()->get('/usage', ['start_date' => $startDate, 'end_date' => $endDate]),
        ));
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl((string) config('services.pixelforge.base_url'))
            ->withToken((string) config('services.pixelforge.api_key'))
            ->timeout((int) config('services.pixelforge.timeout', 10))
            ->acceptJson()
            ->asJson();
    }

    /**
     * Run a request and turn a transport failure into "outcome unknown".
     *
     * @param  callable(): Response  $request
     */
    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw new PixelForgeOutcomeUnknown('Could not reach Pixelforge: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        if ($response->successful()) {
            $json = $response->json();

            if (! is_array($json)) {
                throw new PixelForgeOutcomeUnknown('Pixelforge returned an unreadable response.', httpStatus: $response->status());
            }

            return $json;
        }

        $this->throwFor($response);
    }

    private function throwFor(Response $response): never
    {
        $status = $response->status();
        $code = $response->json('error.code');
        $code = is_string($code) ? $code : null;
        $message = $response->json('error.message');
        $message = is_string($message) ? $message : "Pixelforge returned HTTP {$status}.";

        // Daily limit resets at the UTC day boundary, so retrying soon is pointless.
        if ($status === 429 && $code !== 'daily_limit_exceeded') {
            throw new PixelForgeRetryable($message, $this->retryAfter($response), $code, $status);
        }

        if ($status === 503) {
            throw new PixelForgeRetryable($message, $this->retryAfter($response), $code ?? 'overloaded', $status);
        }

        if (in_array($status, [400, 401, 404, 409, 422, 429], true)) {
            throw new PixelForgeRejected($message, $code, $status);
        }

        throw new PixelForgeOutcomeUnknown($message, $code, $status);
    }

    private function retryAfter(Response $response): int
    {
        $seconds = (int) $response->header('Retry-After');

        return $seconds > 0 ? $seconds : self::DEFAULT_RETRY_AFTER_SECONDS;
    }
}
