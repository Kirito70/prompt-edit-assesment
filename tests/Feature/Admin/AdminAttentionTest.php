<?php

use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
});

function attentionPage(): Assert|TestResponse
{
    return test()->actingAs(test()->admin)->get(route('admin.dashboard'));
}

function webhookEvent(string $id, array $attributes = []): WebhookEvent
{
    return WebhookEvent::create(array_merge([
        'provider_event_id' => $id,
        'provider_generation_id' => 'gen_x',
        'event_type' => 'generation.succeeded',
        'payload' => [],
        'received_at' => now()->subMinutes(30),
    ], $attributes));
}

describe('stuck generations', function () {
    test('flags unfinished generations older than 15 minutes, oldest first', function () {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $old = Generation::factory()->for($owner)->create(['created_at' => now()->subMinutes(40)]);
        $older = Generation::factory()->for($owner)->processing()->create(['created_at' => now()->subHours(3)]);
        Generation::factory()->for($owner)->create(['created_at' => now()->subMinutes(14)]);

        attentionPage()->assertInertia(fn (Assert $page) => $page
            ->where('attention.stuck_total', 2)
            ->has('attention.stuck', 2)
            ->where('attention.stuck.0.id', $older->id)
            ->where('attention.stuck.0.email', 'owner@example.com')
            ->where('attention.stuck.0.status', 'processing')
            ->where('attention.stuck.0.minutes', 180)
            ->where('attention.stuck.1.id', $old->id));
    });

    test('does not flag finished generations or settled failures', function () {
        $user = User::factory()->create();
        Generation::factory()->for($user)->succeeded()->create(['created_at' => now()->subDay()]);
        Generation::factory()->for($user)->failed()->create(['created_at' => now()->subDay()]);

        attentionPage()->assertInertia(fn (Assert $page) => $page
            ->where('attention.stuck_total', 0)
            ->has('attention.stuck', 0));
    });

    test('lists at most ten but counts them all', function () {
        Generation::factory()->count(13)->create(['created_at' => now()->subHour()]);

        attentionPage()->assertInertia(fn (Assert $page) => $page
            ->where('attention.stuck_total', 13)
            ->has('attention.stuck', 10));
    });
});

describe('webhook backlog', function () {
    test('counts events that were never applied after 5 minutes', function () {
        webhookEvent('evt_old');
        webhookEvent('evt_fresh', ['received_at' => now()->subMinutes(2)]);
        webhookEvent('evt_done', ['processed_at' => now()]);

        attentionPage()->assertInertia(fn (Assert $page) => $page
            ->where('attention.events_unprocessed', 1)
            ->where('attention.events_with_errors', 0));
    });

    test('counts events that failed with an error', function () {
        webhookEvent('evt_bad', ['processing_error' => 'No matching generation yet.']);
        webhookEvent('evt_ok');

        attentionPage()->assertInertia(fn (Assert $page) => $page
            ->where('attention.events_unprocessed', 2)
            ->where('attention.events_with_errors', 1));
    });
});

test('everything is zero when all is well', function () {
    attentionPage()->assertInertia(fn (Assert $page) => $page
        ->where('attention.stuck_total', 0)
        ->where('attention.events_unprocessed', 0)
        ->where('attention.events_with_errors', 0));
});
