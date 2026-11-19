<?php

use App\Events\GenerationUpdated;
use App\Jobs\SubmitGenerationToPixelForge;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\PixelForge\WebhookEventProcessor;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

// Medium quality, 100 characters: estimate 0.072343750000, high end 0.086431250000.
const HIGH_ESTIMATE = '0.086431250000';

function creditsInput(array $overrides = []): array
{
    return array_merge([
        'client_request_id' => (string) Str::uuid(),
        'prompt' => str_repeat('a', 100),
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
    ], $overrides);
}

function creditsSettle(Generation $generation, string $type, string $eventId): void
{
    $object = [
        'id' => $generation->provider_generation_id,
        'status' => $type === 'generation.succeeded' ? 'succeeded' : 'failed',
        'metadata' => ['generation_id' => $generation->id],
        'rates' => [
            'input_text_tokens' => '5.00', 'input_image_tokens' => '10.00',
            'output_tokens' => '40.00', 'reasoning_tokens' => '12.50',
        ],
        'usage' => $type === 'generation.succeeded'
            ? ['input_text_tokens' => 27, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1500, 'total_tokens' => 2627]
            : null,
        'image_url' => 'https://cdn.test/img.png',
        'image_url_expires_at' => '2026-11-05T14:03:11Z',
        'error' => $type === 'generation.failed' ? ['code' => 'x', 'message' => 'y'] : null,
        'completed_at' => '2026-11-04T14:03:11Z',
    ];

    $event = WebhookEvent::create([
        'provider_event_id' => $eventId,
        'provider_generation_id' => $generation->provider_generation_id,
        'event_type' => $type,
        'payload' => ['id' => $eventId, 'type' => $type, 'data' => ['object' => $object]],
        'received_at' => now(),
    ]);

    app(WebhookEventProcessor::class)->process($event);
}

beforeEach(function () {
    fakePixelForge();
    Queue::fake();
});

describe('buying credits', function () {
    test('guests cannot buy', function () {
        $this->post(route('credits.store'), ['package' => 'standard'])->assertRedirect(route('login'));
    });

    test('a purchase adds the package amount and records it', function () {
        $user = User::factory()->create(['balance' => '1.500000000000']);

        $this->actingAs($user)
            ->post(route('credits.store'), ['package' => 'standard'])
            ->assertRedirect();

        expect($user->fresh()->balance)->toBe('11.500000000000');

        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $user->id,
            'type' => 'purchase',
            'amount' => '10.000000000000',
            'balance_after' => '11.500000000000',
        ]);
    });

    test('an unknown package is rejected and changes nothing', function () {
        $user = User::factory()->create(['balance' => '0']);

        $this->actingAs($user)
            ->post(route('credits.store'), ['package' => 'free-money'])
            ->assertSessionHasErrors('package');

        expect($user->fresh()->balance)->toBe('0.000000000000')
            ->and(CreditTransaction::count())->toBe(0);
    });

    test('the amount can never come from the request', function () {
        $user = User::factory()->create(['balance' => '0']);

        $this->actingAs($user)
            ->post(route('credits.store'), ['package' => 'small', 'amount' => '9999'])
            ->assertRedirect();

        expect($user->fresh()->balance)->toBe('5.000000000000');
    });

    test('shares the balance and packages with every page', function () {
        $user = User::factory()->create(['balance' => '3.000000000000']);

        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('credits.balance', '3.000000000000')
            ->where('credits.available', '3.000000000000')
            ->has('credits.packages', 3));
    });
});

describe('blocking generation', function () {
    test('a user with no credits cannot generate', function () {
        $user = User::factory()->create(['balance' => '0']);

        $this->actingAs($user)
            ->post(route('generate.store'), creditsInput())
            ->assertSessionHasErrors('credits');

        expect(Generation::count())->toBe(0);
        Queue::assertNothingPushed();
    });

    test('a balance below the highest the price could be is refused', function () {
        $user = User::factory()->create(['balance' => '0.086431249999']);

        $this->actingAs($user)->post(route('generate.store'), creditsInput())->assertSessionHasErrors('credits');

        expect(Generation::count())->toBe(0);
    });

    test('exactly enough is allowed and the amount is held', function () {
        $user = User::factory()->create(['balance' => HIGH_ESTIMATE]);

        $this->actingAs($user)->post(route('generate.store'), creditsInput())->assertSessionHasNoErrors();

        $generation = Generation::sole();
        expect($generation->reserved_credits)->toBe(HIGH_ESTIMATE)
            ->and($user->fresh()->balance)->toBe(HIGH_ESTIMATE); // nothing is spent until it finishes
        Queue::assertPushed(SubmitGenerationToPixelForge::class);
    });

    test('money held for unfinished generations cannot be spent twice', function () {
        $user = User::factory()->create(['balance' => '0.100000000000']);

        $this->actingAs($user)->post(route('generate.store'), creditsInput())->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('generate.store'), creditsInput())->assertSessionHasErrors('credits');

        expect(Generation::count())->toBe(1);
    });

    test('retrying a request that was already accepted is not blocked', function () {
        $user = User::factory()->create(['balance' => HIGH_ESTIMATE]);
        $input = creditsInput();

        $this->actingAs($user)->post(route('generate.store'), $input)->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('generate.store'), $input)->assertSessionHasNoErrors();

        expect(Generation::count())->toBe(1);
    });

    test('with no price shown, a user needs some free credit to continue', function () {
        fakePixelForge(['https://pixelforge.test/v1/pricing' => Http::response([], 500)]);

        $broke = User::factory()->create(['balance' => '0']);
        $this->actingAs($broke)
            ->post(route('generate.store'), creditsInput(['confirmed_without_estimate' => true]))
            ->assertSessionHasErrors('credits');

        $funded = User::factory()->create(['balance' => '1']);
        $this->actingAs($funded)
            ->post(route('generate.store'), creditsInput(['confirmed_without_estimate' => true]))
            ->assertSessionHasNoErrors();
    });

    test('credits are per user', function () {
        User::factory()->create(['balance' => '100']);
        $poor = User::factory()->create(['balance' => '0']);

        $this->actingAs($poor)->post(route('generate.store'), creditsInput())->assertSessionHasErrors('credits');
    });
});

describe('settling', function () {
    function heldGeneration(User $user): Generation
    {
        return Generation::factory()->for($user)->processing()->create(['reserved_credits' => HIGH_ESTIMATE]);
    }

    test('a succeeded generation takes its real charge from the balance', function () {
        $user = User::factory()->create(['balance' => '1.000000000000']);
        $generation = heldGeneration($user);

        creditsSettle($generation, 'generation.succeeded', 'evt_1');

        // 0.062885 provider × 1.25 = 0.07860625
        expect($user->fresh()->balance)->toBe('0.921393750000');
        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $user->id,
            'type' => 'charge',
            'generation_id' => $generation->id,
            'amount' => '-0.078606250000',
            'balance_after' => '0.921393750000',
        ]);
        expect($generation->fresh()->reserved_credits)->toBe('0.000000000000');
    });

    test('a repeated event never charges twice', function () {
        $user = User::factory()->create(['balance' => '1.000000000000']);
        $generation = heldGeneration($user);

        creditsSettle($generation, 'generation.succeeded', 'evt_1');
        creditsSettle($generation, 'generation.succeeded', 'evt_2');

        expect($user->fresh()->balance)->toBe('0.921393750000')
            ->and(CreditTransaction::where('type', 'charge')->count())->toBe(1);
    });

    test('a failed generation costs nothing and frees the hold', function () {
        $user = User::factory()->create(['balance' => '1.000000000000']);
        $generation = heldGeneration($user);

        creditsSettle($generation, 'generation.failed', 'evt_1');

        expect($user->fresh()->balance)->toBe('1.000000000000')
            ->and($generation->fresh()->reserved_credits)->toBe('0.000000000000')
            ->and(CreditTransaction::count())->toBe(0);
    });

    test('the hold is released once settled, so the money is spendable again', function () {
        $user = User::factory()->create(['balance' => '0.100000000000']);
        $this->actingAs($user)->post(route('generate.store'), creditsInput())->assertSessionHasNoErrors();

        creditsSettle(Generation::sole()->forceFill(['provider_generation_id' => 'gen_x']), 'generation.failed', 'evt_1');

        expect($user->fresh()->availableCredits())->toBe('0.100000000000');
    });
});

describe('credit history page', function () {
    test('guests are redirected to login', function () {
        $this->get(route('credits.index'))->assertRedirect(route('login'));
    });

    test('lists only my transactions, newest first, with their generation', function () {
        $user = User::factory()->create(['balance' => '0']);
        $other = User::factory()->create(['balance' => '0']);
        $generation = Generation::factory()->for($user)->processing()->create(['reserved_credits' => '0']);

        CreditTransaction::create(['user_id' => $user->id, 'type' => 'purchase', 'amount' => '10', 'balance_after' => '10', 'created_at' => now()->subHour()]);
        CreditTransaction::create(['user_id' => $user->id, 'generation_id' => $generation->id, 'type' => 'charge', 'amount' => '-0.5', 'balance_after' => '9.5', 'created_at' => now()]);
        CreditTransaction::create(['user_id' => $other->id, 'type' => 'purchase', 'amount' => '25', 'balance_after' => '25']);

        $this->actingAs($user)->get(route('credits.index'))->assertInertia(fn (Assert $page) => $page
            ->component('credits/index')
            ->where('balance', '0.000000000000')
            ->has('transactions.data', 2)
            ->where('transactions.data.0.type', 'charge')
            ->where('transactions.data.0.amount', '-0.500000000000')
            ->where('transactions.data.0.balance_after', '9.500000000000')
            ->where('transactions.data.0.generation_id', $generation->id)
            ->where('transactions.data.1.type', 'purchase')
            ->where('transactions.data.1.generation_id', null));
    });

    test('is paginated', function () {
        $user = User::factory()->create();

        foreach (range(1, 20) as $i) {
            CreditTransaction::create(['user_id' => $user->id, 'type' => 'purchase', 'amount' => '1', 'balance_after' => (string) $i]);
        }

        $this->actingAs($user)->get(route('credits.index'))->assertInertia(fn (Assert $page) => $page
            ->has('transactions.data', 15)
            ->where('transactions.total', 20));
    });
});

describe('the balance in the nav stays current', function () {
    test('is a live prop on the signed in users own channel', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $live = json_encode($this->actingAs($user)->get(route('dashboard'))->viewData('page')['liveProps'] ?? null);

        expect($live)->toContain('credits')
            ->and($live)->toContain("users.{$user->id}.generations")
            ->and($live)->not->toContain("users.{$other->id}.generations")
            ->and($live)->toContain('generation.updated');
    });

    test('a failed generation frees its hold and announces it', function () {
        Event::fake([GenerationUpdated::class]);
        $user = User::factory()->create(['balance' => '0.100000000000']);
        $this->actingAs($user)->post(route('generate.store'), creditsInput())->assertSessionHasNoErrors();
        expect($user->fresh()->availableCredits())->toBe('0.013568750000');

        creditsSettle(Generation::sole()->forceFill(['provider_generation_id' => 'gen_x']), 'generation.failed', 'evt_1');

        Event::assertDispatched(GenerationUpdated::class, fn ($e) => $e->status === 'failed' && $e->userId === $user->id);
        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('credits.available', '0.100000000000'));
    });
});
