<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('the demo seeder refuses to run in production', function () {
    $this->app['env'] = 'production';

    expect(fn () => $this->app->make(DatabaseSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'production');

    expect(User::count())->toBe(0);
});

test('the demo seeder creates the demo admin outside production', function () {
    $this->app->make(DatabaseSeeder::class)->run();

    $admin = User::where('email', DatabaseSeeder::DEMO_EMAIL)->sole();

    expect($admin->is_admin)->toBeTrue();
});
