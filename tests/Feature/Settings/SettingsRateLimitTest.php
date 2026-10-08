<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * @return TestResponse<Response>
 */
function attemptAvatarUpload(TestCase $test, User $user): TestResponse
{
    return $test->actingAs($user)->post(route('avatar.store'));
}

/**
 * @return TestResponse<Response>
 */
function attemptPasswordChange(TestCase $test, User $user): TestResponse
{
    return $test->actingAs($user)->put(route('user-password.update'), [
        'current_password' => 'wrong-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);
}

it('bounds avatar uploads without spending the password budget', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 20) as $ignored) {
        expect(attemptAvatarUpload($this, $user)->status())->not->toBe(429);
    }

    attemptAvatarUpload($this, $user)->assertTooManyRequests();

    attemptPasswordChange($this, $user)->assertSessionHasErrors('current_password');
});

it('bounds password changes without spending the avatar budget', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 6) as $ignored) {
        attemptPasswordChange($this, $user)->assertSessionHasErrors('current_password');
    }

    attemptPasswordChange($this, $user)->assertTooManyRequests();

    expect(attemptAvatarUpload($this, $user)->status())->not->toBe(429);
});

it('counts each person separately', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 6) as $ignored) {
        attemptPasswordChange($this, $user);
    }

    attemptPasswordChange($this, User::factory()->create())->assertSessionHasErrors('current_password');
});
