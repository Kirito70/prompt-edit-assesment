<?php

use App\Events\GenerationUpdated;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

const WH_URL = '/api/webhooks/pixelforge';

beforeEach(function () {
    config(['services.pixelforge.webhook_secret' => 'whsec_test']);
});

function whGeneration(string $status, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 'gen_abc',
        'object' => 'generation',
        'status' => $status,
        'prompt' => 'a fox',
        'metadata' => [],
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'usage' => null,
        'image_url' => null,
        'image_url_expires_at' => null,
        'error' => null,
        'completed_at' => null,
    ], $overrides);
}

function whSucceeded(array $overrides = []): array
{
    // 0.000135 + 0.044 + 0.01875 = 0.062885 provider, × 1.25 = 0.07860625
    return whGeneration('succeeded', array_replace_recursive([
        'usage' => [
            'input_text_tokens' => 27,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 1500,
            'total_tokens' => 2627,
        ],
        'image_url' => 'https://cdn.test/img.png',
        'image_url_expires_at' => '2026-11-05T14:03:11Z',
        'completed_at' => '2026-11-04T14:03:11Z',
    ], $overrides));
}

function whFailed(array $overrides = []): array
{
    return whGeneration('failed', array_replace_recursive([
        'error' => ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'],
        'completed_at' => '2026-11-04T14:03:11Z',
    ], $overrides));
}

function whEvent(string $type, array $generation, string $id = 'evt_1'): array
{
    return [
        'id' => $id,
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-04T14:03:11Z',
        'data' => ['object' => $generation],
    ];
}

function whPost(array $payload, ?int $timestamp = null, string $secret = 'whsec_test'): TestResponse
{
    $body = json_encode($payload);
    $timestamp ??= time();
    $signature = "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    return test()->call('POST', WH_URL, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $signature,
    ], $body);
}

function whGen(array $attributes = []): Generation
{
    return Generation::factory()->create(array_merge(['provider_generation_id' => 'gen_abc'], $attributes));
}

describe('signature', function () {
    test('rejects a request with no signature header', function () {
        $this->call('POST', WH_URL, [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(whEvent('ping', [])))
            ->assertUnauthorized();

        expect(WebhookEvent::count())->toBe(0);
    });

    test('rejects a wrong secret, a stale timestamp and a tampered body', function () {
        $payload = whEvent('generation.succeeded', whSucceeded());
        $generation = whGen();

        whPost($payload, secret: 'whsec_wrong')->assertUnauthorized();
        whPost($payload, timestamp: time() - 301)->assertUnauthorized();

        $body = json_encode($payload);
        $timestamp = time();
        $this->call('POST', WH_URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", 'whsec_test'),
        ], str_replace('a fox', 'a cat', $body))->assertUnauthorized();

        expect(WebhookEvent::count())->toBe(0)
            ->and($generation->fresh()->status)->toBe('queued');
    });

    test('fails closed when no secret is configured', function () {
        config(['services.pixelforge.webhook_secret' => null]);

        whPost(whEvent('ping', []), secret: '')->assertUnauthorized();
    });
});

describe('settlement', function () {
    test('a succeeded event stores usage, rates, image and the exact charge', function () {
        $generation = whGen(['status' => 'processing', 'submission_status' => 'accepted']);

        whPost(whEvent('generation.succeeded', whSucceeded()))->assertOk();

        $generation->refresh();

        expect($generation->status)->toBe('succeeded')
            ->and($generation->provider_cost)->toBe('0.062885000000')
            ->and($generation->customer_charge)->toBe('0.078606250000')
            ->and($generation->usage['output_tokens'])->toBe(1100)
            ->and($generation->rates['output_tokens'])->toBe('40.00')
            ->and($generation->image_url)->toBe('https://cdn.test/img.png')
            ->and($generation->image_url_expires_at->toIso8601String())->toBe('2026-11-05T14:03:11+00:00')
            ->and($generation->provider_completed_at->toIso8601String())->toBe('2026-11-04T14:03:11+00:00')
            ->and($generation->settled_at)->not->toBeNull();
    });

    test('a failed event settles at zero cost with the error', function () {
        $generation = whGen(['status' => 'processing']);

        whPost(whEvent('generation.failed', whFailed()))->assertOk();

        $generation->refresh();

        expect($generation->status)->toBe('failed')
            ->and($generation->provider_cost)->toBe('0.000000000000')
            ->and($generation->customer_charge)->toBe('0.000000000000')
            ->and($generation->error_code)->toBe('generation_failed')
            ->and($generation->error_message)->toBe('The model could not produce an image.')
            ->and($generation->settled_at)->not->toBeNull();
    });

    test('a processing event moves a queued generation forward and keeps its rates', function () {
        $generation = whGen(['status' => 'queued']);

        whPost(whEvent('generation.processing', whGeneration('processing')))->assertOk();

        $generation->refresh();

        expect($generation->status)->toBe('processing')
            ->and($generation->submission_status)->toBe('accepted')
            ->and($generation->rates['reasoning_tokens'])->toBe('12.50')
            ->and($generation->settled_at)->toBeNull();
    });

    test('bills with the rates on the event, not the current pricing', function () {
        $generation = whGen();

        whPost(whEvent('generation.succeeded', whSucceeded(['rates' => ['output_tokens' => '80.00']])))->assertOk();

        // output 1100 × 80 / 1M = 0.088 instead of 0.044
        expect($generation->fresh()->provider_cost)->toBe('0.106885000000');
    });
});

describe('duplicates and ordering', function () {
    test('the same event delivered twice is recorded and applied once', function () {
        $generation = whGen();
        $payload = whEvent('generation.succeeded', whSucceeded());

        whPost($payload)->assertOk();
        $settledAt = $generation->fresh()->settled_at;

        $this->travel(5)->seconds();
        whPost($payload)->assertOk();

        expect(WebhookEvent::count())->toBe(1)
            ->and($generation->fresh()->settled_at->equalTo($settledAt))->toBeTrue()
            ->and($generation->fresh()->customer_charge)->toBe('0.078606250000');
    });

    test('a different event for an already settled generation never changes the charge', function () {
        $generation = whGen();

        whPost(whEvent('generation.succeeded', whSucceeded(), 'evt_1'))->assertOk();
        whPost(whEvent('generation.failed', whFailed(), 'evt_2'))->assertOk();
        whPost(whEvent('generation.processing', whGeneration('processing'), 'evt_3'))->assertOk();

        $generation->refresh();

        expect($generation->status)->toBe('succeeded')
            ->and($generation->customer_charge)->toBe('0.078606250000')
            ->and($generation->error_code)->toBeNull()
            ->and(WebhookEvent::whereNotNull('processed_at')->count())->toBe(3);
    });

    test('a processing event arriving after success does not move the status back', function () {
        $generation = whGen(['status' => 'succeeded', 'settled_at' => now()]);

        whPost(whEvent('generation.processing', whGeneration('processing')))->assertOk();

        expect($generation->fresh()->status)->toBe('succeeded');
    });

    test('success arriving before the processing event still settles', function () {
        $generation = whGen(['status' => 'queued']);

        whPost(whEvent('generation.succeeded', whSucceeded(), 'evt_2'))->assertOk();
        whPost(whEvent('generation.processing', whGeneration('processing'), 'evt_1'))->assertOk();

        expect($generation->fresh()->status)->toBe('succeeded');
    });
});

describe('correlation and retention', function () {
    test('an event for an unknown generation is retained and acknowledged', function () {
        whPost(whEvent('generation.succeeded', whSucceeded()))->assertAccepted();

        $event = WebhookEvent::sole();

        expect($event->provider_event_id)->toBe('evt_1')
            ->and($event->provider_generation_id)->toBe('gen_abc')
            ->and($event->event_type)->toBe('generation.succeeded')
            ->and($event->processed_at)->toBeNull()
            ->and($event->processing_error)->not->toBeNull();
    });

    test('finds the generation by our metadata id when the provider id is not saved yet', function () {
        $generation = whGen(['provider_generation_id' => null, 'submission_status' => 'unknown']);

        whPost(whEvent('generation.succeeded', whSucceeded(['metadata' => ['generation_id' => $generation->id]])))->assertOk();

        $generation->refresh();

        expect($generation->provider_generation_id)->toBe('gen_abc')
            ->and($generation->submission_status)->toBe('accepted')
            ->and($generation->status)->toBe('succeeded');
    });

    test('ping events are recorded and acknowledged', function () {
        whPost(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'created_at' => '2026-11-04T14:03:11Z'])->assertOk();

        $event = WebhookEvent::sole();

        expect($event->provider_generation_id)->toBeNull()
            ->and($event->processed_at)->not->toBeNull();
    });

    test('unknown event types are recorded and ignored', function () {
        whGen();

        whPost(whEvent('generation.archived', whGeneration('succeeded')))->assertOk();

        expect(WebhookEvent::sole()->processed_at)->not->toBeNull();
    });

    test('a payload without an event id is a bad request', function () {
        whPost(['type' => 'ping'])->assertStatus(400);

        expect(WebhookEvent::count())->toBe(0);
    });

    test('a bad payload is retained with the error and answered with 500 so Pixelforge retries', function () {
        $generation = whGen();

        // succeeded but usage missing: cannot be priced
        whPost(whEvent('generation.succeeded', whGeneration('succeeded')))->assertStatus(500);

        $event = WebhookEvent::sole();

        expect($event->processed_at)->toBeNull()
            ->and($event->processing_error)->not->toBeNull()
            ->and($generation->fresh()->settled_at)->toBeNull();
    });
});

describe('pixelforge:process-webhooks', function () {
    test('settles retained events once their generation exists', function () {
        whPost(whEvent('generation.succeeded', whSucceeded()))->assertAccepted();
        $generation = whGen();

        $this->artisan('pixelforge:process-webhooks')->assertSuccessful();

        expect($generation->fresh()->status)->toBe('succeeded')
            ->and(WebhookEvent::sole()->processed_at)->not->toBeNull()
            ->and(WebhookEvent::sole()->processing_error)->toBeNull();
    });

    test('leaves events with no generation for the next run', function () {
        whPost(whEvent('generation.succeeded', whSucceeded()))->assertAccepted();

        $this->artisan('pixelforge:process-webhooks')->assertSuccessful();

        expect(WebhookEvent::sole()->processed_at)->toBeNull();
    });

    test('applies retained events oldest first so ordering rules hold', function () {
        $generation = whGen(['status' => 'queued']);
        WebhookEvent::create([
            'provider_event_id' => 'evt_old', 'provider_generation_id' => 'gen_abc',
            'event_type' => 'generation.processing', 'payload' => whEvent('generation.processing', whGeneration('processing'), 'evt_old'),
            'received_at' => now()->subMinute(),
        ]);
        WebhookEvent::create([
            'provider_event_id' => 'evt_new', 'provider_generation_id' => 'gen_abc',
            'event_type' => 'generation.succeeded', 'payload' => whEvent('generation.succeeded', whSucceeded(), 'evt_new'),
            'received_at' => now(),
        ]);

        $this->artisan('pixelforge:process-webhooks')->assertSuccessful();

        expect($generation->fresh()->status)->toBe('succeeded')
            ->and(WebhookEvent::whereNull('processed_at')->count())->toBe(0);
    });
});

describe('live updates', function () {
    beforeEach(function () {
        Event::fake([GenerationUpdated::class]);
    });

    test('settling a generation announces it to its owner', function () {
        $generation = whGen(['status' => 'processing']);

        whPost(whEvent('generation.succeeded', whSucceeded()))->assertOk();

        Event::assertDispatchedTimes(GenerationUpdated::class, 1);
        Event::assertDispatched(GenerationUpdated::class, fn (GenerationUpdated $event) => $event->generationId === $generation->id
            && $event->userId === $generation->user_id
            && $event->status === 'succeeded');
    });

    test('a failure and a move to processing are announced too', function () {
        whGen(['status' => 'queued']);

        whPost(whEvent('generation.processing', whGeneration('processing'), 'evt_1'))->assertOk();
        whPost(whEvent('generation.failed', whFailed(), 'evt_2'))->assertOk();

        Event::assertDispatchedTimes(GenerationUpdated::class, 2);
        Event::assertDispatched(GenerationUpdated::class, fn (GenerationUpdated $e) => $e->status === 'processing');
        Event::assertDispatched(GenerationUpdated::class, fn (GenerationUpdated $e) => $e->status === 'failed');
    });

    test('a duplicate delivery does not announce again', function () {
        whGen();
        $payload = whEvent('generation.succeeded', whSucceeded());

        whPost($payload)->assertOk();
        whPost($payload)->assertOk();

        Event::assertDispatchedTimes(GenerationUpdated::class, 1);
    });

    test('events that change nothing are not announced', function () {
        whGen();

        whPost(whEvent('generation.succeeded', whSucceeded(), 'evt_1'))->assertOk();
        whPost(whEvent('generation.failed', whFailed(), 'evt_2'))->assertOk();
        whPost(whEvent('generation.processing', whGeneration('processing'), 'evt_3'))->assertOk();

        Event::assertDispatchedTimes(GenerationUpdated::class, 1);
    });

    test('nothing is announced when the generation is unknown or the event cannot be applied', function () {
        whPost(whEvent('generation.succeeded', whSucceeded(), 'evt_unknown'))->assertAccepted();

        whGen();
        whPost(whEvent('generation.succeeded', whGeneration('succeeded'), 'evt_bad'))->assertStatus(500);

        Event::assertNotDispatched(GenerationUpdated::class);
    });
});
