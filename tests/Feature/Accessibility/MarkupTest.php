<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * The parts of an accessibility review a machine can hold on to (TASK-180-007). The keyboard
 * walkthrough is a person's job and belongs to the browser pass; these three are the rules that
 * quietly break when somebody adds a screen or a control, and nobody notices until it is used.
 */

/**
 * A component's source, and the source of every `@/` component it imports one level down.
 *
 * One level, not the whole tree: a heading two components deep is a heading nobody would find
 * when reading either file, and the check would stop meaning anything.
 *
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
 * The layouts `app.ts` gives a page, by the same rule it uses.
 *
 * @return list<string>
 */
function layoutsFor(string $page): array
{
    return match (true) {
        $page === 'Welcome' => [],
        str_starts_with($page, 'auth/') => [resource_path('js/layouts/AuthLayout.vue')],
        str_starts_with($page, 'settings/') => [
            resource_path('js/layouts/AppLayout.vue'),
            resource_path('js/layouts/settings/Layout.vue'),
        ],
        default => [resource_path('js/layouts/AppLayout.vue')],
    };
}

/**
 * Whether a source renders the top of a screen's outline.
 *
 * Four shapes, because the application has four: a literal `<h1>`, the shared `Heading`
 * component in its default variant — which renders `h1` — `Heading` told explicitly which
 * level it is, which is how a page whose title is set small still opens the outline, and
 * `ModalShell` with a title.
 *
 * A modal is the fourth because it is named differently. It renders over a page that already
 * opens an outline, so a second `<h1>` would give a reader two level-one headings in one
 * document. What names a dialog is `aria-labelledby`, and `ModalShell` is the one component
 * that wires it — so requiring the shell and its title is the same guarantee, expressed the way
 * a dialog expresses it.
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

        // The icons this file imports, so a component that is only an icon can be told from a
        // component that renders words.
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

    /*
     * Two, and each one is a decision somebody had to make.
     *
     * The two-factor QR code is an SVG the server generates from Fortify's own secret — no part
     * of it comes from anybody's input.
     *
     * A task's description *is* somebody's input, and it is drawn as markup because a person
     * wrote it in an editor. What makes that safe is upstream and testable: `RichText::sanitize`
     * reduces it to an allowlist inside `CreateTask` and `UpdateTask`, so the column can only
     * ever hold tags this application draws (`RichTextTest`). Nothing else may render markup
     * without the same guarantee, which is what this list is for.
     */
    expect($offenders)->toBe(['TwoFactorSetupModal.vue', 'TaskDescriptionField.vue']);
});
