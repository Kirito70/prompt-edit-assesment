<?php

namespace App\Http\Requests\PromptEdit;

use App\Concerns\GenerationInputRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EstimateGenerationRequest extends FormRequest
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
        return $this->generationRules();
    }
}
