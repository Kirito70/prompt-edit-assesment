<?php

namespace App\Http\Controllers\PromptEdit;

use App\Http\Controllers\Controller;
use App\Http\Requests\PromptEdit\EstimateGenerationRequest;
use App\Services\PixelForge\Exceptions\PixelForgeException;
use App\Services\PixelForgeService;
use Illuminate\Http\JsonResponse;

class GenerationEstimateController extends Controller
{
    /**
     * Price a generation before it is submitted. Only what the user pays is
     * returned; provider cost and profit stay on the server.
     */
    public function __invoke(EstimateGenerationRequest $request, PixelForgeService $pixelForge): JsonResponse
    {
        try {
            $estimate = $pixelForge->estimate(
                $request->string('prompt')->toString(),
                $request->string('size')->toString(),
                $request->string('quality')->toString(),
                $request->referenceImages(),
            );
        } catch (PixelForgeException $e) {
            report($e);

            return response()->json([
                'message' => __('Pricing is temporarily unavailable. Please try again in a moment.'),
            ], 503);
        }

        return response()->json([
            'customer_charge' => $estimate['customer_charge'],
            'range' => $estimate['range'],
            'tokens' => $estimate['tokens'],
        ]);
    }
}
