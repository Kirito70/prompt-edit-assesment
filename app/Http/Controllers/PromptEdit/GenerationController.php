<?php

namespace App\Http\Controllers\PromptEdit;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    private const PER_PAGE = 15;

    /**
     * List the authenticated user's generations, newest first.
     */
    public function index(Request $request): Response
    {
        $generations = $request->user()
            ->generations()
            ->latest()
            ->paginate(self::PER_PAGE)
            ->through(fn (Generation $generation): array => $this->toRow($generation));

        return Inertia::render('prompt-edit/generations/history', [
            'generations' => $generations,
        ]);
    }

    /**
     * Show one of the authenticated user's generations.
     *
     * The lookup is scoped to the user, so another user's ID is a 404.
     */
    public function show(Request $request, string $generation): Response
    {
        $generation = $request->user()
            ->generations()
            ->findOrFail($generation);

        return Inertia::render('prompt-edit/generations/show', [
            'generation' => $this->toDetail($generation),
        ]);
    }

    /**
     * Fields shown in the history list. Provider internals are never exposed.
     *
     * @return array<string, mixed>
     */
    private function toRow(Generation $generation): array
    {
        return [
            'id' => $generation->id,
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'status' => $generation->status,
            'estimated_cost' => $generation->estimated_customer_charge,
            'actual_cost' => $generation->customer_charge,
            'image_url' => $generation->image_url,
            'image_url_expires_at' => $generation->image_url_expires_at?->toIso8601String(),
            'error_message' => $generation->error_message,
            'created_at' => $generation->created_at?->toIso8601String(),
        ];
    }

    /**
     * The list fields plus what the detail page needs.
     *
     * @return array<string, mixed>
     */
    private function toDetail(Generation $generation): array
    {
        return [
            ...$this->toRow($generation),
            'reference_images' => $generation->reference_images,
            'submission_status' => $generation->submission_status,
            'error_code' => $generation->error_code,
            'provider_completed_at' => $generation->provider_completed_at?->toIso8601String(),
        ];
    }
}
