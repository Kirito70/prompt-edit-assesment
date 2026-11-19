<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are sent to the login page', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});

test('a signed in user who is not an admin is forbidden', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

test('an admin can open the admin dashboard', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/AdminDashboard'));
});

test('is_admin cannot be set by mass assignment', function () {
    $user = User::create(['name' => 'x', 'email' => 'x@example.com', 'password' => 'secret-pass-123', 'is_admin' => true]);

    expect($user->fresh()->is_admin)->toBeFalse();
});

test('every admin route is forbidden to a normal user, whatever the filter', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.dashboard', ['start' => '2026-11-01', 'end' => '2026-11-19']))
        ->assertForbidden();
});
