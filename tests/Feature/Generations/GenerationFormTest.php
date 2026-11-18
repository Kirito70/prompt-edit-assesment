<?php

use App\Jobs\SubmitGenerationToPixelForge;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function formInput(array $overrides = []): array
{
    return array_merge([
        'prompt' => str_repeat('a', 100),
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
    ], $overrides);
}

function storeInput(array $overrides = []): array
{
    return formInput(array_merge(['client_request_id' => (string) Str::uuid()], $overrides));
}

beforeEach(function () {
    fakePixelForge();
});

describe('create page', function () {
    test('guests are redirected to login', function () {
        $this->get(route('generate'))->assertRedirect(route('login'));
    });

    test('renders the form with its limits and options', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('generate'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('prompt-edit/generations/create')
                ->where('limits.prompt', 4000)
                ->where('limits.reference_images', 4)
                ->where('sizes', ['1024x1024', '1536x1024', '1024x1536'])
                ->where('qualities', ['low', 'medium', 'high']));
    });
});

describe('estimate endpoint', function () {
    test('returns a price and range and never the provider cost', function () {
        $this->actingAs(User::factory()->create())
            ->postJson(route('generate.estimate'), formInput())
            ->assertOk()
            ->assertExactJson([
                'customer_charge' => '0.072343750000',
                'range' => ['low' => '0.058256250000', 'high' => '0.086431250000'],
                'tokens' => [
                    'input_text_tokens' => 25,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1100,
                    'reasoning_tokens' => 1100,
                ],
            ]);
    });

    test('adds reference image tokens', function () {
        $this->actingAs(User::factory()->create())
            ->postJson(route('generate.estimate'), formInput(['reference_images' => ['https://a.test/1.png']]))
            ->assertOk()
            ->assertJsonPath('tokens.input_image_tokens', 765);
    });

    test('rejects invalid input per field', function (array $overrides, string $field) {
        $this->actingAs(User::factory()->create())
            ->postJson(route('generate.estimate'), formInput($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'no prompt' => [['prompt' => ''], 'prompt'],
        'blank prompt' => [['prompt' => '   '], 'prompt'],
        'prompt too long' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
        'unknown size' => [['size' => '10x10'], 'size'],
        'unknown quality' => [['quality' => 'ultra'], 'quality'],
        'five images' => [['reference_images' => array_fill(0, 5, 'https://a.test/i.png')], 'reference_images'],
        'non http image' => [['reference_images' => ['ftp://a.test/i.png']], 'reference_images.0'],
        'script image' => [['reference_images' => ['javascript:alert(1)']], 'reference_images.0'],
    ]);

    test('ignores blank reference image rows', function () {
        $this->actingAs(User::factory()->create())
            ->postJson(route('generate.estimate'), formInput(['reference_images' => ['', 'https://a.test/1.png', null]]))
            ->assertOk()
            ->assertJsonPath('tokens.input_image_tokens', 765);
    });

    test('answers 503 when pricing is unreachable', function () {
        fakePixelForge(['https://pixelforge.test/v1/pricing' => Http::response('down', 500)]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('generate.estimate'), formInput())
            ->assertStatus(503)
            ->assertJsonStructure(['message']);
    });

    test('requires a signed in user', function () {
        $this->postJson(route('generate.estimate'), formInput())->assertUnauthorized();
    });
});

describe('store', function () {
    test('creates a queued generation with a server computed estimate and starts the submit job', function () {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('generate.store'), storeInput([
            'reference_images' => ['https://a.test/1.png'],
        ]));

        $generation = Generation::sole();

        $response->assertRedirect(route('history.show', $generation));

        expect($generation->user_id)->toBe($user->id)
            ->and($generation->status)->toBe('queued')
            ->and($generation->submission_status)->toBe('pending')
            ->and($generation->prompt)->toBe(str_repeat('a', 100))
            ->and($generation->reference_images)->toBe(['https://a.test/1.png'])
            // 25 text + 765 image + 1100 output + 1100 reasoning
            ->and($generation->estimated_customer_charge)->toBe('0.081906250000')
            ->and(Str::isUuid($generation->provider_idempotency_key))->toBeTrue()
            ->and($generation->provider_generation_id)->toBeNull()
            ->and($generation->customer_charge)->toBeNull();

        Queue::assertPushed(SubmitGenerationToPixelForge::class, 1);
    });

    test('never trusts price, owner or status sent by the client', function () {
        Queue::fake();
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->post(route('generate.store'), storeInput([
            'estimated_customer_charge' => '0.000000000001',
            'customer_charge' => '0.000000000001',
            'user_id' => $other->id,
            'status' => 'succeeded',
            'provider_generation_id' => 'gen_forged',
        ]));

        $generation = Generation::sole();

        expect($generation->user_id)->toBe($user->id)
            ->and($generation->status)->toBe('queued')
            ->and($generation->estimated_customer_charge)->toBe('0.072343750000')
            ->and($generation->customer_charge)->toBeNull()
            ->and($generation->provider_generation_id)->toBeNull();
    });

    test('a repeated submission with the same client request id creates one generation and one job', function () {
        Queue::fake();
        $user = User::factory()->create();
        $input = storeInput();

        $first = $this->actingAs($user)->post(route('generate.store'), $input);
        $second = $this->actingAs($user)->post(route('generate.store'), $input);

        expect(Generation::count())->toBe(1)
            ->and($second->headers->get('Location'))->toBe($first->headers->get('Location'));

        Queue::assertPushed(SubmitGenerationToPixelForge::class, 1);
    });

    test('the same client request id from two users makes two generations', function () {
        Queue::fake();
        $input = storeInput();

        $this->actingAs(User::factory()->create())->post(route('generate.store'), $input);
        $this->actingAs(User::factory()->create())->post(route('generate.store'), $input);

        expect(Generation::count())->toBe(2);
    });

    test('a retry does not call the pricing API again', function () {
        Queue::fake();
        $user = User::factory()->create();
        $input = storeInput();

        $this->actingAs($user)->post(route('generate.store'), $input);
        Http::fake();
        $this->actingAs($user)->post(route('generate.store'), $input);

        Http::assertNothingSent();
    });

    test('validation errors come back per field and nothing is created', function (array $overrides, string $field) {
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post(route('generate.store'), storeInput($overrides))
            ->assertSessionHasErrors($field);

        expect(Generation::count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        'no prompt' => [['prompt' => ''], 'prompt'],
        'bad size' => [['size' => 'big'], 'size'],
        'bad quality' => [['quality' => 'ultra'], 'quality'],
        'five images' => [['reference_images' => array_fill(0, 5, 'https://a.test/i.png')], 'reference_images'],
        'bad client id' => [['client_request_id' => 'not-a-uuid'], 'client_request_id'],
    ]);

    test('when pricing is unreachable it explains and creates nothing', function () {
        Queue::fake();
        fakePixelForge(['https://pixelforge.test/v1/pricing' => Http::response('down', 500)]);

        $this->actingAs(User::factory()->create())
            ->post(route('generate.store'), storeInput())
            ->assertSessionHasErrors('estimate');

        expect(Generation::count())->toBe(0);
        Queue::assertNothingPushed();
    });

    describe('when the price cannot be worked out', function () {
        beforeEach(function () {
            fakePixelForge(['https://pixelforge.test/v1/pricing' => Http::response('down', 500)]);
        });

        test('still refuses by default', function () {
            Queue::fake();

            $this->actingAs(User::factory()->create())
                ->post(route('generate.store'), storeInput(['confirmed_without_estimate' => false]))
                ->assertSessionHasErrors('estimate');

            expect(Generation::count())->toBe(0);
        });

        test('creates the generation with no quote once the user has confirmed', function () {
            Queue::fake();
            $user = User::factory()->create();

            $response = $this->actingAs($user)
                ->post(route('generate.store'), storeInput(['confirmed_without_estimate' => true]));

            $generation = Generation::sole();

            $response->assertRedirect(route('history.show', $generation));

            expect($generation->user_id)->toBe($user->id)
                ->and($generation->status)->toBe('queued')
                ->and($generation->submission_status)->toBe('pending')
                ->and($generation->estimated_customer_charge)->toBeNull()
                ->and($generation->customer_charge)->toBeNull();

            Queue::assertPushed(SubmitGenerationToPixelForge::class, 1);
        });

        test('history and detail show a missing quote as empty, not as zero', function () {
            Queue::fake();
            $user = User::factory()->create();
            $this->actingAs($user)->post(route('generate.store'), storeInput(['confirmed_without_estimate' => true]));
            $generation = Generation::sole();

            $this->get(route('history'))
                ->assertInertia(fn (Assert $page) => $page->where('generations.data.0.estimated_cost', null));

            $this->get(route('history.show', $generation))
                ->assertInertia(fn (Assert $page) => $page->where('generation.estimated_cost', null));
        });
    });

    test('the confirmation never overrides a real price', function () {
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post(route('generate.store'), storeInput(['confirmed_without_estimate' => true]));

        expect(Generation::sole()->estimated_customer_charge)->toBe('0.072343750000');
    });

    test('the confirmation flag must be a boolean', function () {
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post(route('generate.store'), storeInput(['confirmed_without_estimate' => 'maybe']))
            ->assertSessionHasErrors('confirmed_without_estimate');
    });

    test('guests are redirected to login', function () {
        $this->post(route('generate.store'), storeInput())->assertRedirect(route('login'));
    });
});
