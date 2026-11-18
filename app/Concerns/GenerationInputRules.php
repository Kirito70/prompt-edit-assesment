<?php

namespace App\Concerns;

use App\Models\Generation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait GenerationInputRules
{
    /**
     * Rules shared by the cost estimate and the real submission.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    protected function generationRules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:'.Generation::MAX_PROMPT_LENGTH],
            'size' => ['required', Rule::in(Generation::SIZES)],
            'quality' => ['required', Rule::in(Generation::QUALITIES)],
            'reference_images' => ['array', 'max:'.Generation::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['string', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * The form keeps empty rows while a user is typing; they are not errors.
     */
    protected function dropBlankReferenceImages(): void
    {
        $images = $this->input('reference_images');

        $this->merge([
            'reference_images' => is_array($images)
                ? array_values(array_filter($images, fn (mixed $url): bool => is_string($url) && trim($url) !== ''))
                : [],
        ]);
    }

    /**
     * The validated reference image URLs, in order.
     *
     * @return list<string>
     */
    public function referenceImages(): array
    {
        $images = $this->validated('reference_images', []);

        return is_array($images) ? array_values(array_filter($images, is_string(...))) : [];
    }
}
