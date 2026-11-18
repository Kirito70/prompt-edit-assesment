<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\PixelForge\Exceptions\PixelForgeOutcomeUnknown;
use App\Services\PixelForge\Exceptions\PixelForgeRejected;
use App\Services\PixelForge\Exceptions\PixelForgeRetryable;
use App\Services\PixelForge\WebhookEventProcessor;
use App\Services\PixelForgeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends one generation to Pixelforge.
 *
 * Every attempt sends the same Idempotency-Key and body, so retrying after a
 * timeout can never create a second (billed) generation.
 */
class SubmitGenerationToPixelForge implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    private const MAX_BACKOFF_SECONDS = 60;

    public int $tries = 10;

    public function __construct(public readonly string $generationId) {}

    public function handle(PixelForgeService $pixelForge, WebhookEventProcessor $processor): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation === null || $generation->provider_generation_id !== null || $this->isFinal($generation)) {
            return;
        }

        try {
            $response = $pixelForge->createGeneration(
                prompt: $generation->prompt,
                size: $generation->size,
                quality: $generation->quality,
                referenceImages: $generation->reference_images,
                idempotencyKey: $generation->provider_idempotency_key,
                // Lets a webhook find this row before we have saved Pixelforge's id.
                metadata: ['generation_id' => $generation->id],
            );
        } catch (PixelForgeRetryable $e) {
            // Nothing was created. Wait at least as long as Pixelforge asked.
            $this->release(max($e->retryAfterSeconds, $this->retryDelay()));

            return;
        } catch (PixelForgeOutcomeUnknown) {
            // It may or may not exist. Retrying with the same key is safe either way.
            $generation->update(['submission_status' => 'unknown']);
            $this->release($this->retryDelay());

            return;
        } catch (PixelForgeRejected $e) {
            $this->reject($generation, $e->errorCode, $e->getMessage());

            return;
        }

        $this->accept($response);
        $this->applyRetainedEvents($response['id'], $processor);
    }

    /**
     * Out of attempts. Only fail the generation when we know Pixelforge never
     * created it; if the outcome is unknown a webhook may still settle it.
     */
    public function failed(?Throwable $exception): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation === null || $generation->submission_status !== 'pending' || $this->isFinal($generation)) {
            return;
        }

        $this->reject(
            $generation,
            'submission_failed',
            'The image service is busy right now. Nothing was charged, please try again.',
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function accept(array $response): void
    {
        DB::transaction(function () use ($response): void {
            $generation = Generation::query()->whereKey($this->generationId)->lockForUpdate()->firstOrFail();

            $generation->provider_generation_id ??= $response['id'];
            $generation->submission_status = 'accepted';

            if ($generation->rates === null && is_array($response['rates'] ?? null)) {
                $generation->rates = $response['rates'];
            }

            $generation->save();
        });
    }

    private function reject(Generation $generation, ?string $code, string $message): void
    {
        $zero = '0.000000000000';

        DB::transaction(function () use ($generation, $code, $message, $zero): void {
            $locked = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            if ($this->isFinal($locked)) {
                return;
            }

            $locked->update([
                'status' => 'failed',
                'submission_status' => 'rejected',
                'provider_cost' => $zero,
                'customer_charge' => $zero,
                'error_code' => $code,
                'error_message' => $message,
                'settled_at' => now(),
            ]);
        });
    }

    /**
     * Webhooks can arrive before this job saved Pixelforge's id. They were
     * kept; apply them now, oldest first.
     */
    private function applyRetainedEvents(string $providerGenerationId, WebhookEventProcessor $processor): void
    {
        WebhookEvent::query()
            ->whereNull('processed_at')
            ->where('provider_generation_id', $providerGenerationId)
            ->orderBy('received_at')
            ->orderBy('id')
            ->each(function (WebhookEvent $event) use ($processor): void {
                try {
                    $processor->process($event);
                } catch (Throwable $e) {
                    // Saved on the event; pixelforge:process-webhooks retries it.
                    report($e);
                }
            });
    }

    private function isFinal(Generation $generation): bool
    {
        return $generation->settled_at !== null || in_array($generation->status, ['succeeded', 'failed'], true);
    }

    private function retryDelay(): int
    {
        return min(self::MAX_BACKOFF_SECONDS, 2 ** max(1, $this->attempts()));
    }
}
