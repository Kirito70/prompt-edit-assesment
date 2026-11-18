<?php

use App\Events\GenerationUpdated;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

describe('GenerationUpdated event', function () {
    test('goes to the owners channel and to the generations own channel, both private', function () {
        $channels = (new GenerationUpdated('gen-uuid', 7, 'succeeded'))->broadcastOn();

        expect($channels)->each->toBeInstanceOf(PrivateChannel::class)
            ->and(collect($channels)->map(fn (PrivateChannel $c) => $c->name)->all())
            ->toBe(['private-users.7.generations', 'private-generations.gen-uuid']);
    });

    test('carries only the id and status', function () {
        expect((new GenerationUpdated('gen-uuid', 7, 'failed'))->broadcastWith())
            ->toBe(['id' => 'gen-uuid', 'status' => 'failed']);
    });

    test('has a stable name that does not depend on the PHP class', function () {
        expect((new GenerationUpdated('g', 1, 'queued'))->broadcastAs())->toBe('generation.updated');
    });

    test('is queued and only sent after the database transaction commits', function () {
        $event = new GenerationUpdated('g', 1, 'queued');

        expect($event)->toBeInstanceOf(ShouldBroadcast::class)
            ->and($event)->toBeInstanceOf(ShouldDispatchAfterCommit::class);
    });
});

describe('live props on the pages', function () {
    test('history refreshes when one of the signed in users generations changes', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $live = json_encode(
            $this->actingAs($user)->get(route('history'))->viewData('page')['liveProps'] ?? null,
        );

        expect($live)->toContain("users.{$user->id}.generations")
            ->and($live)->not->toContain("users.{$other->id}.generations")
            ->and($live)->toContain('generation.updated');
    });

    test('the detail page refreshes only for its own generation', function () {
        $user = User::factory()->create();
        $generation = Generation::factory()->for($user)->create();
        $another = Generation::factory()->for($user)->create();

        $live = json_encode(
            $this->actingAs($user)->get(route('history.show', $generation))->viewData('page')['liveProps'] ?? null,
        );

        expect($live)->toContain("generations.{$generation->id}")
            ->and($live)->not->toContain("generations.{$another->id}");
    });
});
