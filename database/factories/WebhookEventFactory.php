<?php

namespace Database\Factories;

use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_event_id' => 'evt_'.Str::random(24),
            'provider_generation_id' => 'gen_'.Str::random(24),
            'event_type' => 'generation.succeeded',
            'payload' => [],
            'received_at' => now(),
        ];
    }
}
