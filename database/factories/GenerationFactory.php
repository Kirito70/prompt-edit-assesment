<?php

namespace Database\Factories;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'client_request_id' => (string) Str::uuid(),
            'provider_idempotency_key' => (string) Str::uuid(),
            'prompt' => fake()->sentence(),
            'size' => '1024x1024',
            'quality' => 'standard',
            'reference_images' => [],
            'status' => 'queued',
            'submission_status' => 'pending',
            'estimated_customer_charge' => '0.050000000000',
        ];
    }

    public function processing(): static
    {
        return $this->state(fn (): array => [
            'status' => 'processing',
            'submission_status' => 'accepted',
            'provider_generation_id' => 'gen_'.Str::random(24),
        ]);
    }

    public function succeeded(): static
    {
        return $this->state(fn (): array => [
            'status' => 'succeeded',
            'submission_status' => 'accepted',
            'provider_generation_id' => 'gen_'.Str::random(24),
            'provider_cost' => '0.040000000000',
            'customer_charge' => '0.050000000000',
            'image_url' => 'https://picsum.photos/seed/'.Str::random(8).'/512',
            'image_url_expires_at' => now()->addHour(),
            'provider_completed_at' => now(),
            'settled_at' => now(),
        ]);
    }

    public function expiredImage(): static
    {
        return $this->succeeded()->state(fn (): array => [
            'image_url_expires_at' => now()->subDay(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'failed',
            'submission_status' => 'accepted',
            'provider_generation_id' => 'gen_'.Str::random(24),
            'provider_cost' => '0.000000000000',
            'customer_charge' => '0.000000000000',
            'error_code' => 'content_policy_violation',
            'error_message' => 'The prompt was rejected by the provider.',
            'provider_completed_at' => now(),
            'settled_at' => now(),
        ]);
    }
}
