<?php

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AdminReportRequest extends FormRequest
{
    /** Pixelforge's GET /v1/usage accepts at most 92 days, so the report does too. */
    public const MAX_DAYS = 92;

    private const DEFAULT_DAYS = 30;

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
            'user' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->start()->diffInDays($this->end()) + 1 > self::MAX_DAYS) {
                $validator->errors()->add('end', 'The range can be at most '.self::MAX_DAYS.' days.');
            }
        }];
    }

    public function start(): CarbonImmutable
    {
        return $this->dayInput('start')
            ?? $this->end()->subDays(self::DEFAULT_DAYS - 1);
    }

    public function end(): CarbonImmutable
    {
        return $this->dayInput('end') ?? CarbonImmutable::now('UTC')->startOfDay();
    }

    public function userId(): ?int
    {
        return $this->filled('user') ? $this->integer('user') : null;
    }

    private function dayInput(string $key): ?CarbonImmutable
    {
        $value = $this->input($key);

        return is_string($value) && $value !== ''
            ? CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC')
            : null;
    }
}
