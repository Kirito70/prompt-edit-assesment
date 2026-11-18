<?php

namespace Database\Seeders;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Seeder;

class GenerationSeeder extends Seeder
{
    /**
     * Seed one generation per state for the demo user. Safe to re-run.
     */
    public function run(): void
    {
        $user = User::where('email', DatabaseSeeder::DEMO_EMAIL)->firstOrFail();

        $user->generations()->delete();

        Generation::factory()->for($user)->create();
        Generation::factory()->for($user)->processing()->create();
        Generation::factory()->for($user)->succeeded()->count(3)->create();
        Generation::factory()->for($user)->expiredImage()->create();
        Generation::factory()->for($user)->failed()->create();
    }
}
