<?php

use App\Services\PixelForge\Exceptions\PixelForgeOutcomeUnknown;
use App\Services\PixelForge\Exceptions\PixelForgeRejected;
use App\Services\PixelForge\Exceptions\PixelForgeRetryable;
use App\Services\PixelForgeService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const PF_BASE = 'https://pixelforge.test/v1';

function pixelForgePricing(): array
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

function pixelForgeError(string $code, string $type = 'invalid_request_error'): array
{
    return ['error' => ['type' => $type, 'code' => $code, 'message' => "msg for {$code}"]];
}

beforeEach(function () {
    config([
        'services.pixelforge.base_url' => PF_BASE,
        'services.pixelforge.api_key' => 'pf_test_key',
        'services.pixelforge.timeout' => 5,
    ]);
    Cache::flush();
});

function service(): PixelForgeService
{
    return app(PixelForgeService::class);
}

describe('pricing and estimate', function () {
    test('fetches pricing once and caches it', function () {
        Http::fake([PF_BASE.'/pricing' => Http::response(pixelForgePricing())]);

        service()->pricing();
        service()->pricing();

        Http::assertSentCount(1);
    });

    test('estimates a charge from live pricing', function () {
        Http::fake([PF_BASE.'/pricing' => Http::response(pixelForgePricing())]);

        $estimate = service()->estimate(str_repeat('a', 100), '1024x1024', 'medium', []);

        expect($estimate['customer_charge'])->toBe('0.072343750000');
    });

    test('raises an unknown outcome error when pricing cannot be fetched', function () {
        Http::fake([PF_BASE.'/pricing' => Http::response('boom', 500)]);

        service()->pricing();
    })->throws(PixelForgeOutcomeUnknown::class);
});

describe('createGeneration', function () {
    test('posts the body with bearer auth and the idempotency key', function () {
        Http::fake([PF_BASE.'/generations' => Http::response(['id' => 'gen_1', 'status' => 'queued'], 202)]);

        $generation = service()->createGeneration(
            prompt: 'a red fox',
            size: '1024x1024',
            quality: 'medium',
            referenceImages: ['https://a.test/1.png'],
            idempotencyKey: 'idem-123',
            metadata: ['generation_id' => 'abc'],
        );

        expect($generation['id'])->toBe('gen_1');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === PF_BASE.'/generations'
            && $request->hasHeader('Authorization', 'Bearer pf_test_key')
            && $request->hasHeader('Idempotency-Key', 'idem-123')
            && $request->data() === [
                'prompt' => 'a red fox',
                'size' => '1024x1024',
                'quality' => 'medium',
                'reference_images' => ['https://a.test/1.png'],
                'metadata' => ['generation_id' => 'abc'],
            ]);
    });

    test('sends a byte-identical body on every retry', function () {
        Http::fake([PF_BASE.'/generations' => Http::response(['id' => 'gen_1', 'status' => 'queued'], 202)]);

        foreach ([1, 2] as $_) {
            service()->createGeneration('a red fox', '1024x1024', 'medium', [], 'idem-123', ['generation_id' => 'abc']);
        }

        $bodies = Http::recorded()->map(fn (array $pair) => $pair[0]->body());

        expect($bodies)->toHaveCount(2)->and($bodies[0])->toBe($bodies[1]);
    });

    test('maps retryable rejections and reads Retry-After', function (int $status, string $code, int $retryAfter, int $expected) {
        Http::fake([PF_BASE.'/generations' => Http::response(
            pixelForgeError($code, 'rate_limit_error'),
            $status,
            ['Retry-After' => (string) $retryAfter],
        )]);

        try {
            service()->createGeneration('x', '1024x1024', 'medium', [], 'k');
            $this->fail('Expected PixelForgeRetryable');
        } catch (PixelForgeRetryable $e) {
            expect($e->errorCode)->toBe($code)->and($e->retryAfterSeconds)->toBe($expected);
        }
    })->with([
        'rate limit' => [429, 'rate_limit_exceeded', 12, 12],
        'concurrency' => [429, 'concurrency_limit_exceeded', 5, 5],
        'overloaded' => [503, 'overloaded', 2, 2],
    ]);

    test('defaults Retry-After when the header is missing', function () {
        Http::fake([PF_BASE.'/generations' => Http::response(pixelForgeError('overloaded', 'api_error'), 503)]);

        try {
            service()->createGeneration('x', '1024x1024', 'medium', [], 'k');
            $this->fail('Expected PixelForgeRetryable');
        } catch (PixelForgeRetryable $e) {
            expect($e->retryAfterSeconds)->toBeGreaterThan(0);
        }
    });

    test('maps permanent rejections', function (int $status, string $code) {
        Http::fake([PF_BASE.'/generations' => Http::response(pixelForgeError($code), $status)]);

        try {
            service()->createGeneration('x', '1024x1024', 'medium', [], 'k');
            $this->fail('Expected PixelForgeRejected');
        } catch (PixelForgeRejected $e) {
            expect($e->errorCode)->toBe($code)->and($e->getMessage())->toBe("msg for {$code}");
        }
    })->with([
        'content policy' => [400, 'content_policy_violation'],
        'validation' => [422, 'validation_failed'],
        'key reused' => [409, 'idempotency_key_reused'],
        'bad key' => [401, 'invalid_api_key'],
        'daily limit' => [429, 'daily_limit_exceeded'],
    ]);

    test('treats timeouts and connection errors as an unknown outcome', function () {
        Http::fake([PF_BASE.'/generations' => fn () => throw new ConnectionException('timed out')]);

        service()->createGeneration('x', '1024x1024', 'medium', [], 'k');
    })->throws(PixelForgeOutcomeUnknown::class);

    test('treats unexpected server errors as an unknown outcome', function () {
        Http::fake([PF_BASE.'/generations' => Http::response('<html>bad gateway</html>', 502)]);

        service()->createGeneration('x', '1024x1024', 'medium', [], 'k');
    })->throws(PixelForgeOutcomeUnknown::class);

    test('treats a success response without an id as an unknown outcome', function () {
        Http::fake([PF_BASE.'/generations' => Http::response(['status' => 'queued'], 202)]);

        service()->createGeneration('x', '1024x1024', 'medium', [], 'k');
    })->throws(PixelForgeOutcomeUnknown::class);
});

describe('getGeneration', function () {
    test('fetches a generation by id', function () {
        Http::fake([PF_BASE.'/generations/gen_1' => Http::response(['id' => 'gen_1', 'status' => 'succeeded'])]);

        expect(service()->getGeneration('gen_1')['status'])->toBe('succeeded');
    });

    test('maps not found to a rejection', function () {
        Http::fake([PF_BASE.'/generations/gen_x' => Http::response(pixelForgeError('not_found'), 404)]);

        service()->getGeneration('gen_x');
    })->throws(PixelForgeRejected::class);
});

describe('usage', function () {
    test('asks for the date range with bearer auth and returns the report', function () {
        Http::fake([PF_BASE.'/usage*' => Http::response([
            'object' => 'usage',
            'data' => [['date' => '2026-11-19', 'generations' => 3, 'cost_usd' => '0.123456789']],
            'total' => ['generations' => 3, 'cost_usd' => '0.123456789'],
        ])]);

        $usage = service()->usage('2026-11-01', '2026-11-19');

        expect($usage['data'][0]['cost_usd'])->toBe('0.123456789');

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), '/usage')
            && str_contains($request->url(), 'start_date=2026-11-01')
            && str_contains($request->url(), 'end_date=2026-11-19')
            && $request->hasHeader('Authorization', 'Bearer pf_test_key'));
    });

    test('a rejected range is a permanent rejection', function () {
        Http::fake([PF_BASE.'/usage*' => Http::response(pixelForgeError('date_range_too_long'), 422)]);

        service()->usage('2026-01-01', '2026-11-19');
    })->throws(PixelForgeRejected::class);

    test('a server error is an unknown outcome', function () {
        Http::fake([PF_BASE.'/usage*' => Http::response('boom', 500)]);

        service()->usage('2026-11-01', '2026-11-19');
    })->throws(PixelForgeOutcomeUnknown::class);
});
