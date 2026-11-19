<?php

namespace App\Http\Presenters;

use App\Models\Generation;

/**
 * What the browser may see of a generation. Provider internals (ids, keys,
 * rates, usage, provider cost) are never included.
 */
final class GenerationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(Generation $generation): array
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
     * @return array<string, mixed>
     */
    public static function detail(Generation $generation): array
    {
        return [
            ...self::row($generation),
            'reference_images' => $generation->reference_images,
            'submission_status' => $generation->submission_status,
            'error_code' => $generation->error_code,
            'provider_completed_at' => $generation->provider_completed_at?->toIso8601String(),
        ];
    }
}
