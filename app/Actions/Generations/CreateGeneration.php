<?php

namespace App\Actions\Generations;

use App\Jobs\SubmitGenerationToPixelForge;
use App\Models\Generation;
use App\Models\User;
use App\Services\PixelForge\Exceptions\PixelForgeException;
use App\Services\PixelForgeService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use LogicException;

final class CreateGeneration
{
    public function __construct(private readonly PixelForgeService $pixelForge) {}

    /**
     * Save a user's submission and start sending it to Pixelforge.
     *
     * Safe to call again with the same client request id: the existing
     * generation is returned and nothing is dispatched twice.
     *
     * @param  list<string>  $referenceImages
     * @param  bool  $allowWithoutEstimate  the user confirmed they want to generate even though no price could be shown
     *
     * @throws PixelForgeException when the price cannot be worked out and the user has not confirmed
     */
    public function handle(
        User $user,
        string $clientRequestId,
        string $prompt,
        string $size,
        string $quality,
        array $referenceImages,
        bool $allowWithoutEstimate = false,
    ): Generation {
        $existing = $this->find($user, $clientRequestId);

        if ($existing !== null) {
            return $existing;
        }

        // Priced here, from Pixelforge's published rates; the client's number is never used.
        // Even a confirmed user gets a real quote whenever one can be worked out.
        try {
            $estimate = $this->pixelForge->estimate($prompt, $size, $quality, $referenceImages);
        } catch (PixelForgeException $e) {
            if (! $allowWithoutEstimate) {
                throw $e;
            }

            report($e);
            $estimate = null;
        }

        try {
            $generation = $user->generations()->create([
                'client_request_id' => $clientRequestId,
                'provider_idempotency_key' => (string) Str::uuid(),
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $referenceImages,
                'status' => 'queued',
                'submission_status' => 'pending',
                'estimated_customer_charge' => $estimate['customer_charge'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A second request with the same id won the race; use its row.
            return $this->find($user, $clientRequestId)
                ?? throw new LogicException('Generation vanished after a unique violation.');
        }

        SubmitGenerationToPixelForge::dispatch($generation->id)->afterCommit();

        return $generation;
    }

    private function find(User $user, string $clientRequestId): ?Generation
    {
        return $user->generations()->where('client_request_id', $clientRequestId)->first();
    }
}
