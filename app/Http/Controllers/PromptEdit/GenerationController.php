<?php

namespace App\Http\Controllers\PromptEdit;

use App\Actions\Generations\CreateGeneration;
use App\Events\GenerationUpdated;
use App\Exceptions\InsufficientCredits;
use App\Http\Controllers\Controller;
use App\Http\Presenters\GenerationPresenter;
use App\Http\Requests\PromptEdit\StoreGenerationRequest;
use App\Models\Generation;
use App\Services\PixelForge\Exceptions\PixelForgeException;
use Illuminate\Broadcasting\PrivateChannel;
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
        } catch (InsufficientCredits) {
            return back()
                ->withInput()
                ->withErrors(['credits' => __('You do not have enough credits for this image. Buy credits to continue.')]);
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
        $user = $request->user();

        // Live: refreshed whenever one of this user's generations changes.
        $generations = Inertia::live(
            fn () => $user
                ->generations()
                ->latest()
                ->paginate(self::PER_PAGE)
                ->through(fn (Generation $generation): array => GenerationPresenter::row($generation)),
            on: GenerationUpdated::class,
            channel: new PrivateChannel("users.{$user->id}.generations"),
        );

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

        // Live: refreshed whenever this generation changes.
        return Inertia::render('prompt-edit/generations/show', [
            'generation' => Inertia::live(
                fn () => GenerationPresenter::detail($generation->refresh()),
                on: GenerationUpdated::class,
                channel: new PrivateChannel("generations.{$generation->id}"),
            ),
        ]);
    }
}
