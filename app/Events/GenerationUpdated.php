<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A generation's status changed. The History and detail pages are live props:
 * hearing this makes them re-fetch from the server, so the payload is kept to
 * the bare minimum. It is sent to every subscriber of the channel, so it must
 * never carry anything the owner could not see.
 */
class GenerationUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $generationId,
        public readonly int $userId,
        public readonly string $status,
    ) {}

    /**
     * One channel per user for History, one per generation for the detail page.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("users.{$this->userId}.generations"),
            new PrivateChannel("generations.{$this->generationId}"),
        ];
    }

    /**
     * A stable name, independent of the PHP class.
     */
    public function broadcastAs(): string
    {
        return 'generation.updated';
    }

    /**
     * @return array{id: string, status: string}
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->generationId, 'status' => $this->status];
    }
}
