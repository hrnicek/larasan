<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('unauthenticated user sees login page', function (): void {
    $this->get('/')
        ->assertStatus(200)
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('auth/Login')
                ->where('canResetPassword', true)
        );
});

test('authenticated user is sent to the dashboard', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('dashboard'));
});

test('unverified user is asked to verify before seeing the dashboard', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->followingRedirects()
        ->get('/')
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('auth/VerifyEmail')
        );
});
