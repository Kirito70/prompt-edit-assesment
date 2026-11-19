<?php

namespace App\Services\PixelForge;

use App\Events\GenerationUpdated;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Credits\Wallet;
use App\Services\GenerationPricing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Applies a stored webhook event to its generation.
 *
 * Pixelforge delivers at least once and out of order, so every rule here is
 * about being safe to repeat:
 *  - an event that was already processed is a no-op;
 *  - a generation that is succeeded or failed never changes again, so the
 *    first terminal event decides the charge and later ones are ignored;
 *  - the whole step runs in one transaction with the rows locked, so two
 *    deliveries racing each other cannot both settle a generation.
 */
final class WebhookEventProcessor
{
    private const TERMINAL_STATUSES = ['succeeded', 'failed'];

    public function __construct(
        private readonly GenerationPricing $pricing,
        private readonly Wallet $wallet,
    ) {}

    /**
     * @return bool true when the event is done (applied or deliberately
     *              ignored); false when it must wait for its generation to exist
     *
     * @throws Throwable when the event cannot be applied; the error is saved on the event
     */
    public function process(WebhookEvent $event): bool
    {
        try {
            return DB::transaction(fn (): bool => $this->handle($event));
        } catch (Throwable $e) {
            // Runs after the rollback, so it is not undone with the failed attempt.
            WebhookEvent::query()->whereKey($event->getKey())->update([
                'processing_error' => Str::limit($e->getMessage(), 1000),
            ]);

            throw $e;
        }
    }

    private function handle(WebhookEvent $event): bool
    {
        $event = WebhookEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

        if ($event->processed_at !== null) {
            return true;
        }

        if (! in_array($event->event_type, ['generation.processing', 'generation.succeeded', 'generation.failed'], true)) {
            // ping and event types we do not know: nothing to apply.
            $this->markProcessed($event);

            return true;
        }

        $object = $event->payload['data']['object'] ?? null;

        if (! is_array($object)) {
            throw new InvalidArgumentException('Event has no data.object.');
        }

        $generation = $this->locate($object);

        if ($generation === null) {
            $event->update(['processing_error' => 'No matching generation yet.']);

            return false;
        }

        $this->apply($event->event_type, $generation, $object);
        $this->markProcessed($event);

        return true;
    }

    /**
     * Match by Pixelforge's ID, falling back to the generation ID we sent as
     * metadata, because an event can arrive before we saved Pixelforge's ID.
     *
     * @param  array<string, mixed>  $object
     */
    private function locate(array $object): ?Generation
    {
        $query = Generation::query()->lockForUpdate();

        if (is_string($object['id'] ?? null)) {
            $byProviderId = (clone $query)->where('provider_generation_id', $object['id'])->first();

            if ($byProviderId !== null) {
                return $byProviderId;
            }
        }

        $ourId = $object['metadata']['generation_id'] ?? null;

        return is_string($ourId) && Str::isUuid($ourId)
            ? $query->whereKey($ourId)->first()
            : null;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function apply(string $type, Generation $generation, array $object): void
    {
        // Receiving an event proves Pixelforge accepted the submission.
        $generation->provider_generation_id ??= $object['id'] ?? null;
        $generation->submission_status = 'accepted';

        if ($generation->rates === null && is_array($object['rates'] ?? null)) {
            $generation->rates = $object['rates'];
        }

        if (! $this->isFinal($generation)) {
            match ($type) {
                'generation.processing' => $generation->status = $generation->status === 'queued' ? 'processing' : $generation->status,
                'generation.succeeded' => $this->settleSucceeded($generation, $object),
                'generation.failed' => $this->settleFailed($generation, $object),
                default => throw new InvalidArgumentException("Unsupported event type {$type}."),
            };
        }

        $generation->save();

        // Only a real change is news: duplicates and late events change nothing.
        if ($generation->wasChanged('status')) {
            GenerationUpdated::dispatch($generation->id, $generation->user_id, $generation->status);
        }
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function settleSucceeded(Generation $generation, array $object): void
    {
        $cost = $this->pricing->actual('succeeded', $object['usage'] ?? null, $this->ratesFor($generation, $object));

        $generation->fill([
            'status' => 'succeeded',
            'usage' => $object['usage'],
            'rates' => $cost['rates'],
            'provider_cost' => $cost['provider_cost'],
            'customer_charge' => $cost['customer_charge'],
            'image_url' => $object['image_url'] ?? null,
            'image_url_expires_at' => $this->date($object['image_url_expires_at'] ?? null),
            'provider_completed_at' => $this->date($object['completed_at'] ?? null),
            'reserved_credits' => '0',
            'settled_at' => now(),
        ]);

        // Same transaction as the settlement, and the status check above means it runs once.
        $this->wallet->charge($generation);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function settleFailed(Generation $generation, array $object): void
    {
        $cost = $this->pricing->actual('failed', null, $this->ratesFor($generation, $object));

        $generation->fill([
            'status' => 'failed',
            'provider_cost' => $cost['provider_cost'],
            'customer_charge' => $cost['customer_charge'],
            'error_code' => $object['error']['code'] ?? null,
            'error_message' => $object['error']['message'] ?? null,
            'provider_completed_at' => $this->date($object['completed_at'] ?? null),
            'reserved_credits' => '0',
            'settled_at' => now(),
        ]);
    }

    /**
     * The rates on the event are the ones Pixelforge bills this generation with.
     *
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function ratesFor(Generation $generation, array $object): array
    {
        return is_array($object['rates'] ?? null) ? $object['rates'] : ($generation->rates ?? []);
    }

    private function isFinal(Generation $generation): bool
    {
        return $generation->settled_at !== null
            || in_array($generation->status, self::TERMINAL_STATUSES, true);
    }

    private function markProcessed(WebhookEvent $event): void
    {
        $event->update(['processed_at' => now(), 'processing_error' => null]);
    }

    private function date(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }
}
