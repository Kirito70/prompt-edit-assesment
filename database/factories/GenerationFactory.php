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
}
