<?php

use App\Events\GenerationUpdated;
use App\Jobs\SubmitGenerationToPixelForge;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\PixelForge\WebhookEventProcessor;
use App\Services\PixelForgeService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

const JOB_GEN_URL = 'https://pixelforge.test/v1/generations';

function runSubmitJob(Generation $generation, ?SubmitGenerationToPixelForge $job = null): SubmitGenerationToPixelForge
{
    $job ??= new SubmitGenerationToPixelForge($generation->id);
    $job->withFakeQueueInteractions();
    $job->handle(app(PixelForgeService::class), app(WebhookEventProcessor::class));

    return $job;
}

function pendingGeneration(array $overrides = []): Generation
{
    return Generation::factory()->create(array_merge([
        'prompt' => 'a red fox',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => ['https://a.test/1.png'],
        'status' => 'queued',
        'submission_status' => 'pending',
        'provider_generation_id' => null,
    ], $overrides));
}

function acceptedBody(string $id = 'gen_new'): array
{
    return [
        'id' => $id,
        'object' => 'generation',
        'status' => 'queued',
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
    ];
}

beforeEach(function () {
    fakePixelForge();
});

test('submits with our stored idempotency key and our id as metadata, then saves what came back', function () {
    fakePixelForge([JOB_GEN_URL => Http::response(acceptedBody(), 202)]);
    $generation = pendingGeneration();

    runSubmitJob($generation);

    Http::assertSent(fn (Request $request) => $request->url() === JOB_GEN_URL
        && $request->hasHeader('Idempotency-Key', $generation->provider_idempotency_key)
        && $request->data() === [
            'prompt' => 'a red fox',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => ['https://a.test/1.png'],
            'metadata' => ['generation_id' => $generation->id],
        ]);

    $generation->refresh();

    expect($generation->provider_generation_id)->toBe('gen_new')
        ->and($generation->submission_status)->toBe('accepted')
        ->and($generation->rates['output_tokens'])->toBe('40.00')
        ->and($generation->status)->toBe('queued')
        ->and($generation->settled_at)->toBeNull();
});

test('applies webhook events that arrived before the submission was acknowledged', function () {
    fakePixelForge([JOB_GEN_URL => Http::response(acceptedBody(), 202)]);
    $generation = pendingGeneration();

    WebhookEvent::create([
        'provider_event_id' => 'evt_early',
        'provider_generation_id' => 'gen_new',
        'event_type' => 'generation.failed',
        'payload' => [
            'id' => 'evt_early',
            'type' => 'generation.failed',
            'data' => ['object' => acceptedBody() + ['error' => ['code' => 'generation_failed', 'message' => 'nope'], 'completed_at' => '2026-11-04T14:03:11Z']],
        ],
        'received_at' => now(),
    ]);

    runSubmitJob($generation);

    expect($generation->fresh()->status)->toBe('failed')
        ->and($generation->fresh()->customer_charge)->toBe('0.000000000000')
        ->and(WebhookEvent::sole()->processed_at)->not->toBeNull();
});

test('does not submit twice once Pixelforge has accepted it', function () {
    fakePixelForge([JOB_GEN_URL => Http::response(acceptedBody(), 202)]);
    $generation = pendingGeneration(['provider_generation_id' => 'gen_existing', 'submission_status' => 'accepted']);

    runSubmitJob($generation);

    Http::assertNothingSent();
});

test('does not submit a generation that already finished', function () {
    fakePixelForge([JOB_GEN_URL => Http::response(acceptedBody(), 202)]);

    runSubmitJob(pendingGeneration(['status' => 'failed', 'settled_at' => now()]));

    Http::assertNothingSent();
});

test('releases the job for the Retry-After delay when rate limited', function () {
    fakePixelForge([JOB_GEN_URL => Http::response(
        ['error' => ['type' => 'rate_limit_error', 'code' => 'rate_limit_exceeded', 'message' => 'slow down']],
        429,
        ['Retry-After' => '30'],
    )]);
    $generation = pendingGeneration();

    $job = runSubmitJob($generation);

    $job->assertReleased(delay: 30);

    expect($generation->fresh()->status)->toBe('queued')
        ->and($generation->fresh()->submission_status)->toBe('pending');
});

test('keeps retrying when overloaded and never marks the generation failed', function () {
    fakePixelForge([JOB_GEN_URL => Http::response(
        ['error' => ['type' => 'api_error', 'code' => 'overloaded', 'message' => 'busy']],
        503,
        ['Retry-After' => '2'],
    )]);
    $generation = pendingGeneration();

    $job = runSubmitJob($generation);

    $job->assertReleased();

    expect($generation->fresh()->status)->toBe('queued');
});

test('an unknown outcome is marked unknown and retried with the same key and body', function () {
    fakePixelForge([JOB_GEN_URL => Http::sequence()
        ->pushFailedConnection('timed out')
        ->push(acceptedBody('gen_replayed'), 202)]);
    $generation = pendingGeneration();

    $first = runSubmitJob($generation);
    $first->assertReleased();

    expect($generation->fresh()->submission_status)->toBe('unknown');

    runSubmitJob($generation);

    $posts = Http::recorded()->filter(fn (array $pair) => $pair[0]->url() === JOB_GEN_URL)->values();

    expect($posts)->toHaveCount(2)
        ->and($posts[0][0]->header('Idempotency-Key'))->toBe($posts[1][0]->header('Idempotency-Key'))
        ->and($posts[0][0]->body())->toBe($posts[1][0]->body())
        ->and($generation->fresh()->provider_generation_id)->toBe('gen_replayed')
        ->and($generation->fresh()->submission_status)->toBe('accepted');
});

test('a rejection fails the generation at zero cost with the reason', function (string $code, int $status) {
    fakePixelForge([JOB_GEN_URL => Http::response(
        ['error' => ['type' => 'invalid_request_error', 'code' => $code, 'message' => "msg {$code}"]],
        $status,
    )]);
    $generation = pendingGeneration();

    $job = runSubmitJob($generation);
    $job->assertNotReleased();

    $generation->refresh();

    expect($generation->status)->toBe('failed')
        ->and($generation->submission_status)->toBe('rejected')
        ->and($generation->provider_cost)->toBe('0.000000000000')
        ->and($generation->customer_charge)->toBe('0.000000000000')
        ->and($generation->error_code)->toBe($code)
        ->and($generation->error_message)->toBe("msg {$code}")
        ->and($generation->settled_at)->not->toBeNull();
})->with([
    'content policy' => ['content_policy_violation', 400],
    'validation' => ['validation_failed', 422],
]);

describe('when attempts run out', function () {
    test('a generation Pixelforge never created is failed at zero', function () {
        $generation = pendingGeneration(['submission_status' => 'pending']);

        (new SubmitGenerationToPixelForge($generation->id))->failed(new RuntimeException('gave up'));

        $generation->refresh();

        expect($generation->status)->toBe('failed')
            ->and($generation->customer_charge)->toBe('0.000000000000')
            ->and($generation->error_code)->toBe('submission_failed')
            ->and($generation->settled_at)->not->toBeNull();
    });

    test('an unknown outcome is left open, because Pixelforge may have created it and a webhook can still settle it', function () {
        $generation = pendingGeneration(['submission_status' => 'unknown']);

        (new SubmitGenerationToPixelForge($generation->id))->failed(new RuntimeException('gave up'));

        $generation->refresh();

        expect($generation->status)->toBe('queued')
            ->and($generation->settled_at)->toBeNull()
            ->and($generation->customer_charge)->toBeNull();
    });
});

test('can be pushed onto the real database queue', function () {
    // Queue::fake() and calling handle() directly never serialize the job, which
    // hid a clash with Laravel's own backoff() hook. This pushes it for real.
    config(['queue.default' => 'database']);
    $generation = pendingGeneration();

    SubmitGenerationToPixelForge::dispatch($generation->id);

    expect(DB::table('jobs')->count())->toBe(1);
});

test('a rejection is announced so the page updates', function () {
    Event::fake([GenerationUpdated::class]);
    fakePixelForge([JOB_GEN_URL => Http::response(
        ['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'no']],
        400,
    )]);
    $generation = pendingGeneration();

    runSubmitJob($generation);

    Event::assertDispatchedTimes(GenerationUpdated::class, 1);
    Event::assertDispatched(GenerationUpdated::class, fn (GenerationUpdated $e) => $e->generationId === $generation->id
        && $e->userId === $generation->user_id
        && $e->status === 'failed');
});

test('being accepted is not announced, because nothing visible changed', function () {
    Event::fake([GenerationUpdated::class]);
    fakePixelForge([JOB_GEN_URL => Http::response(acceptedBody(), 202)]);

    runSubmitJob(pendingGeneration());

    Event::assertNotDispatched(GenerationUpdated::class);
});
