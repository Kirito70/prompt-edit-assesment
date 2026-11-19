<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Exception\InvalidOptionException;

describe('user:create', function () {
    test('creates a verified user with a hashed password', function () {
        $this->artisan('user:create', ['email' => 'ops@example.com', '--name' => 'Ops'])
            ->expectsQuestion('Password', 'a-Strong-pass-1')
            ->expectsQuestion('Confirm password', 'a-Strong-pass-1')
            ->assertSuccessful();

        $user = User::where('email', 'ops@example.com')->sole();
        expect($user->name)->toBe('Ops')
            ->and($user->is_admin)->toBeFalse()
            ->and($user->email_verified_at)->not->toBeNull()
            ->and(Hash::check('a-Strong-pass-1', $user->password))->toBeTrue()
            ->and($user->balance)->toBe('0.000000000000');
    });

    test('--admin makes them an admin straight away', function () {
        $this->artisan('user:create', ['email' => 'boss@example.com', '--name' => 'Boss', '--admin' => true])
            ->expectsQuestion('Password', 'a-Strong-pass-1')
            ->expectsQuestion('Confirm password', 'a-Strong-pass-1')
            ->assertSuccessful();

        expect(User::where('email', 'boss@example.com')->value('is_admin'))->toBeTrue();
    });

    test('refuses a weak password and creates nothing', function () {
        $this->artisan('user:create', ['email' => 'ops@example.com', '--name' => 'Ops'])
            ->expectsQuestion('Password', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertFailed();

        expect(User::count())->toBe(0);
    });

    test('refuses mismatched passwords', function () {
        $this->artisan('user:create', ['email' => 'ops@example.com', '--name' => 'Ops'])
            ->expectsQuestion('Password', 'a-Strong-pass-1')
            ->expectsQuestion('Confirm password', 'a-Strong-pass-2')
            ->assertFailed();

        expect(User::count())->toBe(0);
    });

    test('refuses an invalid or taken email', function () {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->artisan('user:create', ['email' => 'not-an-email', '--name' => 'X'])->assertFailed();
        $this->artisan('user:create', ['email' => 'taken@example.com', '--name' => 'X'])->assertFailed();

        expect(User::count())->toBe(1);
    });

    test('never takes the password from an option', function () {
        expect(fn () => Artisan::call('user:create', ['email' => 'ops@example.com', '--password' => 'x']))
            ->toThrow(InvalidOptionException::class);

        expect(User::count())->toBe(0);
    });
});

describe('user:promote', function () {
    test('makes an existing user an admin', function () {
        User::factory()->create(['email' => 'me@example.com', 'is_admin' => false]);

        $this->artisan('user:promote', ['email' => 'me@example.com'])->assertSuccessful();

        expect(User::where('email', 'me@example.com')->value('is_admin'))->toBeTrue();
    });

    test('is safe to repeat', function () {
        User::factory()->admin()->create(['email' => 'me@example.com']);

        $this->artisan('user:promote', ['email' => 'me@example.com'])->assertSuccessful();

        expect(User::where('is_admin', true)->count())->toBe(1);
    });

    test('fails for an unknown email and creates nothing', function () {
        $this->artisan('user:promote', ['email' => 'ghost@example.com'])->assertFailed();

        expect(User::count())->toBe(0);
    });
});

describe('user:revoke-admin', function () {
    test('removes admin rights but keeps the account', function () {
        User::factory()->admin()->create(['email' => 'first@example.com']);
        User::factory()->admin()->create(['email' => 'second@example.com']);

        $this->artisan('user:revoke-admin', ['email' => 'second@example.com'])->assertSuccessful();

        expect(User::where('email', 'second@example.com')->value('is_admin'))->toBeFalse()
            ->and(User::count())->toBe(2);
    });

    test('refuses to remove the last admin', function () {
        User::factory()->admin()->create(['email' => 'only@example.com']);

        $this->artisan('user:revoke-admin', ['email' => 'only@example.com'])->assertFailed();

        expect(User::where('email', 'only@example.com')->value('is_admin'))->toBeTrue();
    });

    test('fails for an unknown email', function () {
        $this->artisan('user:revoke-admin', ['email' => 'ghost@example.com'])->assertFailed();
    });
});
