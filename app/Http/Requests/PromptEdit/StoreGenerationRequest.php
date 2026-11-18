<?php

namespace App\Http\Requests\PromptEdit;

use App\Concerns\GenerationInputRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreGenerationRequest extends FormRequest
{
    use GenerationInputRules;

    protected function prepareForValidation(): void
    {
        $this->dropBlankReferenceImages();
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return $this->generationRules() + [
            // Created once by the form, so a double click or retry is one generation.
            'client_request_id' => ['required', 'uuid'],
            // Set after the user confirms they want to generate with no price shown.
            'confirmed_without_estimate' => ['nullable', 'boolean'],
        ];
    }
}
