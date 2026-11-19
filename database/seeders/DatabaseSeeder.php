<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public const DEMO_EMAIL = 'admin@example.com';

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // This creates an admin with a well-known password, so it must never run for real.
        if (app()->isProduction()) {
            throw new RuntimeException('Refusing to seed demo data in production.');
        }

        User::factory()->createQuietly([
            'name' => 'First Admin',
            'email' => self::DEMO_EMAIL,
            'password' => Hash::make('password'),
            'is_admin' => true,
        ]);

        $this->call(GenerationSeeder::class);
    }
}
