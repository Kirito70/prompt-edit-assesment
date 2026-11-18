<?php

use App\Models\Generation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to login from history and detail pages', function () {
    $generation = Generation::factory()->create();

    $this->get(route('history'))->assertRedirect(route('login'));
    $this->get(route('history.show', $generation))->assertRedirect(route('login'));
});

test('history lists only the signed-in users generations', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->count(2)->create();
    Generation::factory()->count(3)->create();

    $this->actingAs($user)
        ->get(route('history'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('prompt-edit/generations/history')
            ->has('generations.data', 2));
});

test('history rows expose list fields and hide provider internals', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->succeeded()->create();

    $this->actingAs($user)
        ->get(route('history'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('generations.data.0', fn (Assert $row) => $row
                ->hasAll([
                    'id', 'prompt', 'size', 'quality', 'status', 'estimated_cost',
                    'actual_cost', 'image_url', 'image_url_expires_at', 'error_message', 'created_at',
                ])
                ->missing('provider_idempotency_key')
                ->missing('provider_generation_id')
                ->missing('rates')
                ->missing('usage')
                ->missing('provider_cost')));
});

test('detail page returns the generation with its extra fields', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->failed()->create([
        'reference_images' => ['https://example.com/a.png'],
    ]);

    $this->actingAs($user)
        ->get(route('history.show', $generation))
        ->assertInertia(fn (Assert $page) => $page
            ->component('prompt-edit/generations/show')
            ->where('generation.id', $generation->id)
            ->where('generation.status', 'failed')
            ->where('generation.actual_cost', '0.000000000000')
            ->where('generation.reference_images', ['https://example.com/a.png'])
            ->where('generation.error_code', 'content_policy_violation')
            ->has('generation.submission_status')
            ->has('generation.provider_completed_at')
            ->missing('generation.provider_idempotency_key')
            ->missing('generation.rates')
            ->missing('generation.usage')
            ->missing('generation.user'));
});

test('another users generation returns 404', function () {
    $generation = Generation::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('history.show', $generation))
        ->assertNotFound();
});
