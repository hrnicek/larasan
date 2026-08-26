<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\UiTheme;
use App\Http\Middleware\HandleUiTheme;
use App\Models\User;

/*
 * The surface scheme (ADR-0019). The whole mechanism is one attribute on <html> plus a pair of
 * token blocks per theme, so what these tests guard is that the attribute is written server-side
 * — a theme that arrived as page data would repaint after hydration, and every full load would
 * flash the other theme first — and that the blocks behind it are complete and stay off the brand.
 */

test('a new person starts in the default theme', function (): void {
    expect(User::factory()->create()->ui_theme)->toBe(UiTheme::default());
});

test('the root template paints the html element in the stored theme', function (): void {
    $user = User::factory()->create();
    $user->ui_theme = UiTheme::Paper;
    $user->save();

    $this->actingAs($user)
        ->get(route('appearance.edit'))
        ->assertOk()
        ->assertSee('data-theme="paper"', escape: false);
});

test('a guest gets the default theme', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('data-theme="'.UiTheme::default()->value.'"', escape: false);
});

/*
 * The device keeps a copy so that the screens with no user — the sign-in page above all — are not
 * the one place the application forgets what it looks like.
 */
test('a signed out guest keeps the theme the device remembers', function (): void {
    $this->withCookie(HandleUiTheme::COOKIE, 'carbon')
        ->get(route('login'))
        ->assertOk()
        ->assertSee('data-theme="carbon"', escape: false);
});

test('a theme the device no longer knows falls back to the default', function (): void {
    $this->withCookie(HandleUiTheme::COOKIE, 'chartreuse')
        ->get(route('login'))
        ->assertOk()
        ->assertSee('data-theme="'.UiTheme::default()->value.'"', escape: false);
});

test('the stored theme beats the one on the device', function (): void {
    $user = User::factory()->create();
    $user->ui_theme = UiTheme::Carbon;
    $user->save();

    $this->actingAs($user)
        ->withCookie(HandleUiTheme::COOKIE, 'paper')
        ->get(route('appearance.edit'))
        ->assertOk()
        ->assertSee('data-theme="carbon"', escape: false)
        // ...and the device is corrected on the way out, so signing out here does not hand the
        // sign-in page the theme of another session.
        ->assertCookie(HandleUiTheme::COOKIE, 'carbon');
});

test('the device cookie cannot pick a theme for a signed in person', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withCookie(HandleUiTheme::COOKIE, 'paper')
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

    $this->actingAs($mine)->put(route('appearance.update'), ['ui_theme' => 'paper'])->assertRedirect();

    expect($mine->refresh()->ui_theme)->toBe(UiTheme::Paper)
        ->and($theirs->refresh()->ui_theme)->toBe(UiTheme::default());
});

test('a guest cannot switch anyone\'s theme', function (): void {
    $this->put(route('appearance.update'), ['ui_theme' => 'paper'])
        ->assertRedirect(route('login'));
});
