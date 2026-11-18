<?php

namespace App\Services;

use InvalidArgumentException;
use LogicException;

final class GenerationPricing
{
    private const SCALE = 12;

    private const WORKING_SCALE = 20;

    private const MARKUP_MULTIPLIER = '1.25';

    private const CATEGORIES = [
        'input_text_tokens',
        'input_image_tokens',
        'output_tokens',
        'reasoning_tokens',
    ];

    /**
     * Estimate the charge before submitting, from the published pricing.
     *
     * @param  list<string>  $referenceImages
     * @param  array<string, mixed>  $pricing  the GET /v1/pricing response
     * @return array<string, mixed>
     */
    public function estimate(
        string $prompt,
        string $size,
        string $quality,
        array $referenceImages,
        array $pricing,
    ): array {
        if (($pricing['currency'] ?? null) !== 'usd'
            || ($pricing['unit'] ?? null) !== 'per_1m_tokens') {
            throw new InvalidArgumentException(
                'Expected USD pricing per million tokens.',
            );
        }

        $characters = mb_strlen($prompt, 'UTF-8');

        if (trim($prompt) === '' || $characters > 4000) {
            throw new InvalidArgumentException(
                'Prompt must contain 1–4000 characters.',
            );
        }

        if (count($referenceImages) > 4) {
            throw new InvalidArgumentException(
                'At most four reference images are supported.',
            );
        }

        $config = $pricing['tokens'] ?? [];

        $divisor = $this->count(
            $config['characters_per_text_token'] ?? null,
            'characters_per_text_token',
        );

        if ($divisor === 0) {
            throw new InvalidArgumentException(
                'characters_per_text_token must be positive.',
            );
        }

        $perImage = $this->count(
            $config['per_reference_image'] ?? null,
            'per_reference_image',
        );

        $output = $this->count(
            $config['output'][$quality][$size] ?? null,
            'output tokens for the selected quality and size',
        );

        $minimum = $this->count(
            $config['reasoning'][$quality]['min'] ?? null,
            'minimum reasoning tokens',
        );

        $maximum = $this->count(
            $config['reasoning'][$quality]['max'] ?? null,
            'maximum reasoning tokens',
        );

        if ($maximum < $minimum) {
            throw new InvalidArgumentException(
                'Invalid reasoning token range.',
            );
        }

        // Round text tokens upward using integer arithmetic.
        $textTokens = intdiv($characters - 1, $divisor) + 1;

        // Estimate with the midpoint, rounded down if fractional.
        $reasoningTokens = $minimum + intdiv($maximum - $minimum, 2);

        $rates = $pricing['rates'] ?? [];
        $fixed = [
            'input_image_tokens' => count($referenceImages) * $perImage,
            'output_tokens' => $output,
        ];

        $estimate = $this->calculate([
            'input_text_tokens' => $textTokens,
            'reasoning_tokens' => $reasoningTokens,
        ] + $fixed, $rates);

        // Text tokens are only known within ±variance after the generation
        // finishes, and reasoning tokens fall anywhere in the quality's range.
        $percent = $this->variancePercent($config['text_token_variance'] ?? 0);

        $estimate['range'] = [
            'low' => $this->calculate([
                'input_text_tokens' => intdiv($textTokens * (100 - $percent), 100),
                'reasoning_tokens' => $minimum,
            ] + $fixed, $rates)['customer_charge'],
            'high' => $this->calculate([
                'input_text_tokens' => intdiv($textTokens * (100 + $percent) + 99, 100),
                'reasoning_tokens' => $maximum,
            ] + $fixed, $rates)['customer_charge'],
        ];

        return $estimate;
    }

    /**
     * Price a finished generation from its usage and the rates stored on it.
     *
     * @param  array<string, mixed>|null  $usage
     * @param  array<string, mixed>  $rates
     * @return array<string, mixed>
     */
    public function actual(
        string $status,
        ?array $usage,
        array $rates,
    ): array {
        if ($status === 'failed') {
            $zero = '0.000000000000';

            return [
                'currency' => 'usd',
                'markup_multiplier' => self::MARKUP_MULTIPLIER,
                'provider_cost' => $zero,
                'customer_charge' => $zero,
                'profit' => $zero,
                'tokens' => null,
                'rates' => $rates,
                'breakdown' => array_fill_keys(self::CATEGORIES, $zero),
            ];
        }

        if ($status !== 'succeeded') {
            throw new LogicException(
                'Only terminal generations can be priced.',
            );
        }

        if ($usage === null) {
            throw new InvalidArgumentException(
                'Successful generation is missing usage.',
            );
        }

        return $this->calculate($usage, $rates);
    }

    /**
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $rates
     * @return array<string, mixed>
     */
    private function calculate(array $usage, array $rates): array
    {
        $tokens = [];
        $appliedRates = [];
        $breakdown = [];
        $providerCost = '0';

        foreach (self::CATEGORIES as $category) {
            $tokens[$category] = $this->count(
                $usage[$category] ?? null,
                $category,
            );

            $rate = $this->rate($rates[$category] ?? null, $category);

            $appliedRates[$category] = $rate;

            $cost = bcdiv(
                bcmul(
                    (string) $tokens[$category],
                    $rate,
                    self::WORKING_SCALE,
                ),
                '1000000',
                self::WORKING_SCALE,
            );

            $breakdown[$category] = $this->money($cost);

            $providerCost = bcadd(
                $providerCost,
                $cost,
                self::WORKING_SCALE,
            );
        }

        // Apply the markup once to the complete provider cost.
        $customerCharge = bcmul(
            $providerCost,
            self::MARKUP_MULTIPLIER,
            self::WORKING_SCALE,
        );

        $profit = bcsub(
            $customerCharge,
            $providerCost,
            self::WORKING_SCALE,
        );

        return [
            'currency' => 'usd',
            'markup_multiplier' => self::MARKUP_MULTIPLIER,
            'provider_cost' => $this->money($providerCost),
            'customer_charge' => $this->money($customerCharge),
            'profit' => $this->money($profit),
            'tokens' => $tokens,
            'rates' => $appliedRates,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Twelve rate decimals, six for per-million conversion, and two for markup
     * fit within the working scale.
     *
     * @return numeric-string
     */
    private function rate(mixed $value, string $category): string
    {
        if (! is_string($value)
            || ! is_numeric($value)
            || preg_match('/\A[0-9]+(?:\.[0-9]{1,12})?\z/', $value) !== 1) {
            throw new InvalidArgumentException(
                "Invalid decimal-string rate for {$category}.",
            );
        }

        return $value;
    }

    /**
     * The variance as a whole percentage (0.15 becomes 15). It only widens a
     * token count, so it never touches money directly.
     */
    private function variancePercent(mixed $variance): int
    {
        if (! is_int($variance) && ! is_float($variance)) {
            return 0;
        }

        return max(0, min(99, (int) round($variance * 100)));
    }

    private function count(mixed $value, string $field): int
    {
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException(
                "{$field} must be a nonnegative integer.",
            );
        }

        return $value;
    }

    /**
     * @param  numeric-string  $amount
     */
    private function money(string $amount): string
    {
        $formatted = bcadd($amount, '0', self::SCALE);

        // Detect precision loss instead of silently truncating a charge.
        if (bccomp($amount, $formatted, self::WORKING_SCALE) !== 0) {
            throw new LogicException(
                'Amount exceeds the configured 12-decimal storage precision.',
            );
        }

        return $formatted;
    }
}
