<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Services\PixelForge\WebhookEventProcessor;
use App\Services\PixelForge\WebhookSignature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Receives Pixelforge webhooks. Pixelforge needs a 2xx within 10 seconds, so
 * this only verifies, stores and applies the event. The stored row is also what
 * deduplicates deliveries and lets us retry events we could not apply yet.
 */
class PixelForgeWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        WebhookSignature $signature,
        WebhookEventProcessor $processor,
    ): JsonResponse {
        // The signature covers the raw bytes, so verify before decoding anything.
        $rawBody = $request->getContent();

        if (! $signature->verify(
            (string) $request->header('Pixelforge-Signature'),
            $rawBody,
            (string) config('services.pixelforge.webhook_secret'),
        )) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $payload = json_decode($rawBody, true);
        $eventId = is_array($payload) ? ($payload['id'] ?? null) : null;
        $type = is_array($payload) ? ($payload['type'] ?? null) : null;

        if (! is_string($eventId) || $eventId === '' || ! is_string($type)) {
            return response()->json(['error' => 'invalid_event'], 400);
        }

        $event = $this->store($eventId, $type, $payload);

        try {
            $processed = $processor->process($event);
        } catch (Throwable $e) {
            report($e);

            // The event is kept with its error; a non-2xx makes Pixelforge retry.
            return response()->json(['error' => 'processing_failed'], 500);
        }

        // 202: stored, but its generation is not known yet. We apply it later.
        return response()->json(['received' => true], $processed ? 200 : 202);
    }

    /**
     * Insert the event once. A repeat delivery returns the existing row.
     *
     * @param  array<string, mixed>  $payload
     */
    private function store(string $eventId, string $type, array $payload): WebhookEvent
    {
        $existing = WebhookEvent::query()->where('provider_event_id', $eventId)->first();

        if ($existing !== null) {
            return $existing;
        }

        $generationId = $payload['data']['object']['id'] ?? null;

        try {
            return WebhookEvent::create([
                'provider_event_id' => $eventId,
                'provider_generation_id' => is_string($generationId) ? $generationId : null,
                'event_type' => $type,
                'payload' => $payload,
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two deliveries of the same event raced; the other one won.
            return WebhookEvent::query()->where('provider_event_id', $eventId)->firstOrFail();
        }
    }
}
