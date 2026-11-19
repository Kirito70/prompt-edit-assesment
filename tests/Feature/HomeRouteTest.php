<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the home page sends visitors to the dashboard', function () {
    $this->get(route('home'))->assertRedirect(route('dashboard'));
});

test('a guest opening the site ends up on the login page', function () {
    $this->followingRedirects()
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/Login'));
});

test('a signed in user opening the site lands on the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->followingRedirects()
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
});
