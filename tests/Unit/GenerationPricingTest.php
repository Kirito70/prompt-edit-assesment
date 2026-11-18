<?php

use App\Services\GenerationPricing;

/**
 * Worked examples use the published Pixelforge rates (USD per 1M tokens):
 * text 5.00, image 10.00, output 40.00, reasoning 12.50, plus the 25% markup.
 */
function pricingConfig(): array
{
    return [
        'object' => 'pricing',
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];
}

function estimate(string $prompt = 'x', string $size = '1024x1024', string $quality = 'medium', array $images = []): array
{
    return (new GenerationPricing)->estimate($prompt, $size, $quality, $images, pricingConfig());
}

describe('estimate', function () {
    test('prices a 100 character medium square prompt exactly', function () {
        // 25 text + 1100 output + 1100 reasoning (midpoint of 200–2000)
        // 0.000125 + 0.044 + 0.01375 = 0.057875 provider, × 1.25 = 0.07234375
        $result = estimate(str_repeat('a', 100));

        expect($result['tokens'])->toBe([
            'input_text_tokens' => 25,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 1100,
        ])
            ->and($result['provider_cost'])->toBe('0.057875000000')
            ->and($result['customer_charge'])->toBe('0.072343750000')
            ->and($result['profit'])->toBe('0.014468750000');
    });

    test('rounds text tokens up to the next whole token', function () {
        expect(estimate('a')['tokens']['input_text_tokens'])->toBe(1)
            ->and(estimate(str_repeat('a', 4))['tokens']['input_text_tokens'])->toBe(1)
            ->and(estimate(str_repeat('a', 5))['tokens']['input_text_tokens'])->toBe(2);
    });

    test('counts unicode characters rather than bytes', function () {
        // 8 emoji characters = 32 bytes but 2 text tokens.
        expect(estimate(str_repeat('😀', 8))['tokens']['input_text_tokens'])->toBe(2);
    });

    test('bills 765 image tokens per reference image', function () {
        $result = estimate('x', images: ['https://a.test/1.png', 'https://a.test/2.png']);

        expect($result['tokens']['input_image_tokens'])->toBe(1530);
    });

    test('uses the output table for the chosen size and quality', function () {
        expect(estimate(size: '1536x1024', quality: 'high')['tokens']['output_tokens'])->toBe(6450);
    });

    test('rounds the reasoning midpoint down when fractional', function () {
        $config = pricingConfig();
        $config['tokens']['reasoning']['medium'] = ['min' => 1, 'max' => 4];

        $result = (new GenerationPricing)->estimate('x', '1024x1024', 'medium', [], $config);

        expect($result['tokens']['reasoning_tokens'])->toBe(2);
    });

    test('accepts exactly 4000 characters and 4 reference images', function () {
        $result = estimate(str_repeat('a', 4000), images: array_fill(0, 4, 'https://a.test/i.png'));

        expect($result['tokens']['input_text_tokens'])->toBe(1000)
            ->and($result['tokens']['input_image_tokens'])->toBe(3060);
    });

    test('rejects invalid input', function (string $prompt, array $images, string $size, string $quality) {
        estimate($prompt, $size, $quality, $images);
    })->with([
        'empty prompt' => ['', [], '1024x1024', 'medium'],
        'blank prompt' => ["  \n ", [], '1024x1024', 'medium'],
        'too long' => [str_repeat('a', 4001), [], '1024x1024', 'medium'],
        'five images' => ['x', array_fill(0, 5, 'https://a.test/i.png'), '1024x1024', 'medium'],
        'unknown size' => ['x', [], '999x999', 'medium'],
        'unknown quality' => ['x', [], '1024x1024', 'ultra'],
    ])->throws(InvalidArgumentException::class);

    test('rejects non usd or non per million pricing', function () {
        $config = pricingConfig();
        $config['currency'] = 'eur';

        (new GenerationPricing)->estimate('x', '1024x1024', 'medium', [], $config);
    })->throws(InvalidArgumentException::class);
});

describe('actual', function () {
    test('prices a succeeded generation from usage and the rates stored on it', function () {
        // 0.000135 + 0.00765 + 0.044 + 0.01875 = 0.070535, × 1.25 = 0.08816875
        $result = (new GenerationPricing)->actual('succeeded', [
            'input_text_tokens' => 27,
            'input_image_tokens' => 765,
            'output_tokens' => 1100,
            'reasoning_tokens' => 1500,
            'total_tokens' => 3392,
        ], pricingConfig()['rates']);

        expect($result['provider_cost'])->toBe('0.070535000000')
            ->and($result['customer_charge'])->toBe('0.088168750000')
            ->and($result['profit'])->toBe('0.017633750000')
            ->and($result['breakdown']['output_tokens'])->toBe('0.044000000000');
    });

    test('uses the rates it is given, not the current pricing', function () {
        $rates = pricingConfig()['rates'];
        $rates['output_tokens'] = '80.00';

        $result = (new GenerationPricing)->actual('succeeded', [
            'input_text_tokens' => 0,
            'input_image_tokens' => 0,
            'output_tokens' => 1000,
            'reasoning_tokens' => 0,
        ], $rates);

        expect($result['provider_cost'])->toBe('0.080000000000')
            ->and($result['rates']['output_tokens'])->toBe('80.00');
    });

    test('charges nothing for a failed generation', function () {
        $result = (new GenerationPricing)->actual('failed', null, pricingConfig()['rates']);

        expect($result['provider_cost'])->toBe('0.000000000000')
            ->and($result['customer_charge'])->toBe('0.000000000000')
            ->and($result['profit'])->toBe('0.000000000000');
    });

    test('charges nothing for a failed generation even if usage is present', function () {
        $result = (new GenerationPricing)->actual('failed', [
            'input_text_tokens' => 10,
            'input_image_tokens' => 0,
            'output_tokens' => 1000,
            'reasoning_tokens' => 100,
        ], pricingConfig()['rates']);

        expect($result['customer_charge'])->toBe('0.000000000000');
    });

    test('refuses to price a generation that is not finished', function (string $status) {
        (new GenerationPricing)->actual($status, null, pricingConfig()['rates']);
    })->with(['queued', 'processing'])->throws(LogicException::class);

    test('refuses a succeeded generation without usage', function () {
        (new GenerationPricing)->actual('succeeded', null, pricingConfig()['rates']);
    })->throws(InvalidArgumentException::class);

    test('refuses malformed usage and rates', function (array $usage, array $rates) {
        (new GenerationPricing)->actual('succeeded', $usage, $rates);
    })->with([
        'float tokens' => [['input_text_tokens' => 1.5, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0], pricingConfig()['rates']],
        'negative tokens' => [['input_text_tokens' => -1, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0], pricingConfig()['rates']],
        'missing category' => [['input_text_tokens' => 1], pricingConfig()['rates']],
        'float rate' => [['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0], ['input_text_tokens' => 5.0] + pricingConfig()['rates']],
        'scientific rate' => [['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0], ['input_text_tokens' => '5e0'] + pricingConfig()['rates']],
    ])->throws(InvalidArgumentException::class);

    test('keeps fractions of a cent exact with no float drift', function () {
        // 1 token at 5.00 per million = 0.000005; × 1.25 = 0.00000625
        $result = (new GenerationPricing)->actual('succeeded', [
            'input_text_tokens' => 1,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
        ], pricingConfig()['rates']);

        expect($result['provider_cost'])->toBe('0.000005000000')
            ->and($result['customer_charge'])->toBe('0.000006250000');
    });

    test('fails loudly instead of silently truncating beyond 12 decimals', function () {
        $rates = pricingConfig()['rates'];
        $rates['input_text_tokens'] = '0.000000000001';

        (new GenerationPricing)->actual('succeeded', [
            'input_text_tokens' => 1,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
        ], $rates);
    })->throws(LogicException::class);
});

test('estimate and actual agree when usage equals the estimated tokens', function () {
    $estimate = estimate(str_repeat('a', 100));

    $actual = (new GenerationPricing)->actual('succeeded', $estimate['tokens'], pricingConfig()['rates']);

    expect($actual['customer_charge'])->toBe($estimate['customer_charge']);
});
