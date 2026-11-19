<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BuyCreditsRequest extends FormRequest
{
    /**
     * Only a package name is accepted; the amount always comes from config.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'package' => ['required', 'string', Rule::in(array_keys(config('credits.packages')))],
        ];
    }
}
