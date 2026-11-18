<?php

namespace App\Http\Controllers\PromptEdit;

use App\Actions\Generations\CreateGeneration;
use App\Http\Controllers\Controller;
use App\Http\Requests\PromptEdit\StoreGenerationRequest;
use App\Models\Generation;
use App\Services\PixelForge\Exceptions\PixelForgeException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    private const PER_PAGE = 15;

    /**
     * The form for a new generation.
     */
    public function create(): Response
    {
        return Inertia::render('prompt-edit/generations/create', [
            'sizes' => Generation::SIZES,
            'qualities' => Generation::QUALITIES,
            'limits' => [
                'prompt' => Generation::MAX_PROMPT_LENGTH,
                'reference_images' => Generation::MAX_REFERENCE_IMAGES,
            ],
        ]);
    }

    /**
     * Save a submission and send it to Pixelforge in the background.
     */
    public function store(StoreGenerationRequest $request, CreateGeneration $createGeneration): RedirectResponse
    {
        try {
            $generation = $createGeneration->handle(
                $request->user(),
                clientRequestId: $request->string('client_request_id')->toString(),
                prompt: $request->string('prompt')->toString(),
                size: $request->string('size')->toString(),
                quality: $request->string('quality')->toString(),
                referenceImages: $request->referenceImages(),
                allowWithoutEstimate: $request->boolean('confirmed_without_estimate'),
            );
        } catch (PixelForgeException $e) {
            report($e);

            return back()
                ->withInput()
                ->withErrors(['estimate' => __('We could not work out the price right now. Please try again in a moment.')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Generation started.')]);

        return to_route('history.show', $generation);
    }

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
