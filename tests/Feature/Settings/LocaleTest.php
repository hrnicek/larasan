<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\Locale;
use App\Http\Middleware\HandleLocale;
use App\Models\User;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;

// English is the source language: its sentences are the keys of lang/cs.json.

function speaking(Locale $locale): User
{
    $user = User::factory()->create();
    $user->locale = $locale;
    $user->save();

    return $user;
}

test('a new person reads the app in English', function (): void {
    expect(User::factory()->create()->locale)->toBe(Locale::English);
});

test('a guest gets the language the browser asks for', function (): void {
    $this->withHeader('Accept-Language', 'cs-CZ,cs;q=0.9,en;q=0.8')
        ->get(route('login'))
        ->assertOk()
        ->assertSee('<html lang="cs"', escape: false)
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'cs'));
});

test('a browser asking for a language the app lacks gets English', function (): void {
    $this->withHeader('Accept-Language', 'de-DE,de;q=0.9')
        ->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

test('the language a guest chose beats the browser', function (): void {
    $this->withCookie(HandleLocale::COOKIE, 'en')
        ->withHeader('Accept-Language', 'cs')
        ->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

test('a cookie naming an unknown language is ignored', function (): void {
    $this->withCookie(HandleLocale::COOKIE, 'tlh')
        ->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

test('the saved language beats the device and the browser', function (): void {
    $this->actingAs(speaking(Locale::Czech))
        ->withCookie(HandleLocale::COOKIE, 'en')
        ->withHeader('Accept-Language', 'en')
        ->get(route('appearance.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'cs'))
        ->assertCookie(HandleLocale::COOKIE, 'cs');
});

test('the dictionary of the current language is shared with the page', function (): void {
    $this->actingAs(speaking(Locale::Czech))
        ->get(route('appearance.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('translations.Language', 'Jazyk')
            ->where('locales', Locale::options()));
});

test('English needs no dictionary', function (): void {
    $this->actingAs(speaking(Locale::English))
        ->get(route('appearance.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('translations', []));
});

test('server messages follow the saved language', function (): void {
    $user = speaking(Locale::Czech);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => 'Jana Nováková', 'email' => $user->email])
        ->assertSessionHasNoErrors();

    /** @var array<string, array<string, string>> $flashed */
    $flashed = session()->get(SessionKey::FLASH_DATA, []);

    expect($flashed['toast']['message'])->toBe('Profil uložen.');
});

test('every language can be chosen and sticks', function (Locale $locale): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('locale.update'), ['locale' => $locale->value])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertCookie(HandleLocale::COOKIE, $locale->value);

    expect($user->refresh()->locale)->toBe($locale);
})->with(fn () => Locale::cases());

test('a guest can choose a language before signing in', function (): void {
    $this->put(route('locale.update'), ['locale' => 'cs'])
        ->assertRedirect()
        ->assertCookie(HandleLocale::COOKIE, 'cs');
});

test('an unknown language is refused', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('locale.update'), ['locale' => 'tlh'])
        ->assertSessionHasErrors('locale');

    expect($user->refresh()->locale)->toBe(Locale::English);
});

test('signing up keeps the language the form was read in', function (): void {
    $this->withCookie(HandleLocale::COOKIE, 'cs')
        ->post(route('register.store'), [
            'name' => 'Jana Nováková',
            'email' => 'jana@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

    expect(User::query()->where('email', 'jana@example.com')->sole()->locale)->toBe(Locale::Czech);
});

test('mail goes out in the language of the recipient', function (): void {
    expect(speaking(Locale::Czech)->preferredLocale())->toBe('cs');
});
