<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * @return list<string>
 */
function componentWithItsImports(string $path): array
{
    $sources = [];

    if (! File::exists($path)) {
        return $sources;
    }

    $contents = (string) File::get($path);
    $sources[] = $contents;

    preg_match_all("/from '@\/([^']+)'/", $contents, $matches);

    foreach ($matches[1] as $import) {
        $imported = resource_path('js/'.$import);

        if (str_ends_with($imported, '.vue') && File::exists($imported)) {
            $sources[] = (string) File::get($imported);
        }
    }

    return $sources;
}

/**
 * Mirrors the layout resolution in `app.ts`.
 *
 * @return list<string>
 */
function layoutsFor(string $page): array
{
    return match (true) {
        $page === 'Error' => [],
        str_starts_with($page, 'auth/') => [resource_path('js/layouts/AuthLayout.vue')],
        str_starts_with($page, 'settings/') => [
            resource_path('js/layouts/AppLayout.vue'),
            resource_path('js/layouts/settings/Layout.vue'),
        ],
        default => [resource_path('js/layouts/AppLayout.vue')],
    };
}

/**
 * A titled `ModalShell` counts because a dialog is named by `aria-labelledby`, not a second `<h1>`.
 */
function opensAnOutline(string $source): bool
{
    if (str_contains($source, '<h1') || str_contains($source, 'level="h1"')) {
        return true;
    }

    if (preg_match('/<ModalShell\b[^>]*\btitle=/s', $source) === 1) {
        return true;
    }

    preg_match_all('/<Heading\b([^>]*)>|<Heading\b([^\/]*)\/>/s', $source, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $attributes = ($match[1] ?? '').($match[2] ?? '');

        if (! str_contains($attributes, 'variant="small"')) {
            return true;
        }
    }

    return false;
}

it('gives every screen a heading to be found by', function (): void {
    $without = [];

    foreach (File::allFiles(resource_path('js/pages')) as $page) {
        $name = (string) Str::of($page->getPathname())
            ->after(resource_path('js/pages').'/')
            ->beforeLast('.vue');

        $sources = componentWithItsImports($page->getPathname());

        foreach (layoutsFor($name) as $layout) {
            $sources = [...$sources, ...componentWithItsImports($layout)];
        }

        $found = collect($sources)->contains(opensAnOutline(...));

        if (! $found) {
            $without[] = $name;
        }
    }

    expect($without)->toBe([]);
})->with([
    'a screen with no h1 is a screen a reader cannot name — and four of them had none until this
    check was written',
]);

it('names every control that is only an icon', function (): void {
    $unnamed = [];

    foreach ([...File::allFiles(resource_path('js/pages')), ...File::allFiles(resource_path('js/components')), ...File::allFiles(resource_path('js/modules'))] as $file) {
        if (! str_ends_with($file->getFilename(), '.vue')) {
            continue;
        }

        $source = (string) File::get($file->getPathname());

        preg_match_all("/import \{([^}]+)\} from '@lucide\/vue'/", $source, $iconImports);
        $icons = collect(explode(',', implode(',', $iconImports[1])))
            ->map(fn (string $name): string => trim(Str::after($name, ' as ')))
            ->filter()
            ->all();

        if ($icons === []) {
            continue;
        }

        preg_match_all('/<(button|Button)\b([^>]*)>(.*?)<\/\1>/s', $source, $controls, PREG_SET_ORDER);

        foreach ($controls as [$whole, $tag, $attributes, $inner]) {
            $text = trim(preg_replace('/<[^>]+>/', '', $inner) ?? '');
            $named = Str::contains($attributes, ['aria-label', 'aria-labelledby', 'title='])
                || Str::contains($inner, ['sr-only', 'aria-label']);

            $onlyIcons = collect($icons)->contains(fn (string $icon): bool => str_contains($inner, '<'.$icon));

            if ($text === '' && $onlyIcons && ! $named) {
                $unnamed[] = $file->getFilename().' <'.$tag.'>';
            }
        }
    }

    expect($unnamed)->toBe([]);
})->with([
    'an icon is a picture until somebody says what it does; three of these were unnamed until
    this check was written',
]);

it('renders no HTML it did not build, except the one place it does', function (): void {
    $offenders = collect(File::allFiles(resource_path('js')))
        ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.vue')
            && str_contains((string) File::get($file->getPathname()), 'v-html'))
        ->map(fn (SplFileInfo $file): string => $file->getFilename())
        ->values()
        ->all();

    // The QR code is server-generated SVG; descriptions are allowlisted by `RichText::sanitize` on write.
    expect($offenders)->toBe(['TwoFactorSetupModal.vue', 'TaskDescriptionField.vue']);
});
