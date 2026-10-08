<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\UiTheme;
use App\Http\Middleware\HandleUiTheme;
use App\Models\User;

// The theme is rendered server-side; applied after hydration, every full load would flash the default first.

test('a new person starts in the default theme', function (): void {
    expect(User::factory()->create()->ui_theme)->toBe(UiTheme::default());
});

test('the root template paints the html element in the stored theme', function (): void {
    $user = User::factory()->create();
    $user->ui_theme = UiTheme::Ember;
    $user->save();

    $this->actingAs($user)
        ->get(route('appearance.edit'))
        ->assertOk()
        ->assertSee('data-theme="ember"', escape: false);
});

test('a guest gets the default theme', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('data-theme="'.UiTheme::default()->value.'"', escape: false);
});

test('a signed out guest keeps the theme the device remembers', function (): void {
    $this->withCookie(HandleUiTheme::COOKIE, 'nocturne')
        ->get(route('login'))
        ->assertOk()
        ->assertSee('data-theme="nocturne"', escape: false);
});

test('a theme the device no longer knows falls back to the default', function (): void {
    $this->withCookie(HandleUiTheme::COOKIE, 'chartreuse')
        ->get(route('login'))
        ->assertOk()
        ->assertSee('data-theme="'.UiTheme::default()->value.'"', escape: false);
});

test('the stored theme beats the one on the device', function (): void {
    $user = User::factory()->create();
    $user->ui_theme = UiTheme::Nocturne;
    $user->save();

    $this->actingAs($user)
        ->withCookie(HandleUiTheme::COOKIE, 'ember')
        ->get(route('appearance.edit'))
        ->assertOk()
        ->assertSee('data-theme="nocturne"', escape: false)
        // Corrected so that after signing out the sign-in page does not keep another session's theme.
        ->assertCookie(HandleUiTheme::COOKIE, 'nocturne');
});

test('the device cookie cannot pick a theme for a signed in person', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withCookie(HandleUiTheme::COOKIE, 'ember')
        ->get(route('appearance.edit'))
        ->assertOk();

    expect($user->refresh()->ui_theme)->toBe(UiTheme::default());
});

test('every theme can actually be chosen and sticks', function (UiTheme $theme): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('appearance.update'), ['ui_theme' => $theme->value])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($user->refresh()->ui_theme)->toBe($theme);
})->with(fn () => UiTheme::cases());

test('an unknown theme is rejected', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('appearance.update'), ['ui_theme' => 'chartreuse'])
        ->assertSessionHasErrors('ui_theme');

    expect($user->refresh()->ui_theme)->toBe(UiTheme::default());
});

test('the theme is one person\'s choice, not everybody\'s', function (): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    $this->actingAs($mine)->put(route('appearance.update'), ['ui_theme' => 'ember'])->assertRedirect();

    expect($mine->refresh()->ui_theme)->toBe(UiTheme::Ember)
        ->and($theirs->refresh()->ui_theme)->toBe(UiTheme::default());
});

test('a guest cannot switch anyone\'s theme', function (): void {
    $this->put(route('appearance.update'), ['ui_theme' => 'ember'])
        ->assertRedirect(route('login'));
});
