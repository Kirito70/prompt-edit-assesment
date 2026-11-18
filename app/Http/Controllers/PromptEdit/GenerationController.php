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
            ->through(fn (Generation $generation): array => [
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
            ]);

        return Inertia::render('prompt-edit/generations/history', [
            'generations' => $generations,
        ]);
    }
}
