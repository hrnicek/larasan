<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\Locale;
use Symfony\Component\Finder\Finder;

// A key missing from lang/<locale>.json shows up as English in the middle of a translated screen.

/**
 * Every literal passed to t() or tc() in the frontend, keyed by the file that uses it.
 *
 * @return array<string, string>
 */
function frontendTranslationKeys(): array
{
    $keys = [];

    $files = Finder::create()
        ->in(resource_path('js'))
        ->exclude(['actions', 'routes', 'wayfinder'])
        ->name(['*.vue', '*.ts'])
        ->files();

    foreach ($files as $file) {
        preg_match_all("/\\btc?\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $file->getContents(), $matches);

        foreach ($matches[1] as $literal) {
            $keys[stripcslashes($literal)] = $file->getRelativePathname();
        }
    }

    return $keys;
}

/** @return list<string> */
function placeholdersIn(string $line): array
{
    preg_match_all('/:([a-z][A-Za-z_]*)/', $line, $matches);

    $names = array_values(array_unique(array_map('strtolower', $matches[1])));
    sort($names);

    return $names;
}

/** @return array<string, string> */
function dictionaryFor(Locale $locale): array
{
    return json_decode((string) file_get_contents(lang_path($locale->value.'.json')), true, flags: JSON_THROW_ON_ERROR);
}

$translated = array_values(array_filter(Locale::cases(), fn (Locale $locale): bool => ! $locale->isSource()));

test('every language but the source has a dictionary', function (Locale $locale): void {
    expect(lang_path($locale->value.'.json'))->toBeFile();
})->with($translated);

test('every frontend string is translated', function (Locale $locale): void {
    $missing = array_diff_key(frontendTranslationKeys(), dictionaryFor($locale));

    expect($missing)->toBe([]);
})->with($translated);

test('a translation keeps the placeholders of its key', function (Locale $locale): void {
    $broken = [];

    foreach (dictionaryFor($locale) as $key => $line) {
        if (placeholdersIn($key) !== placeholdersIn($line)) {
            $broken[$key] = $line;
        }
    }

    expect($broken)->toBe([]);
})->with($translated);

test('no translation is left empty', function (Locale $locale): void {
    expect(array_filter(dictionaryFor($locale), fn (string $line): bool => trim($line) === ''))->toBe([]);
})->with($translated);
