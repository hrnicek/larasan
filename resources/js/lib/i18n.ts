import { usePage } from '@inertiajs/vue3';

type Replacements = Record<string, string | number>;

/*
 * Mirrors Laravel's JSON translations: the English sentence is the key, `lang/<locale>.json` holds
 * the other languages, and a missing line falls back to the key. The dictionary arrives as the
 * shared `translations` prop, so the same file serves `__()` on the server and `t()` here.
 */

function dictionary(): Record<string, string> {
    const translations = usePage().props.translations;

    return Array.isArray(translations) ? {} : translations;
}

export function currentLocale(): string {
    return usePage().props.locale;
}

function replace(line: string, replacements: Replacements): string {
    return Object.entries(replacements)
        .sort(([a], [b]) => b.length - a.length)
        .reduce(
            (result, [key, value]) =>
                result
                    .replaceAll(`:${key}`, String(value))
                    .replaceAll(
                        `:${key.charAt(0).toUpperCase()}${key.slice(1)}`,
                        String(value).charAt(0).toUpperCase() +
                            String(value).slice(1),
                    ),
            line,
        );
}

export function t(key: string, replacements: Replacements = {}): string {
    return replace(dictionary()[key] ?? key, replacements);
}

/** Index of the plural form for a count, as Laravel's MessageSelector picks it. */
function pluralIndex(locale: string, count: number): number {
    if (locale === 'cs') {
        if (count === 1) {
            return 0;
        }

        return count >= 2 && count <= 4 ? 1 : 2;
    }

    return count === 1 ? 0 : 1;
}

function explicitSegment(segment: string, count: number): string | null {
    const exact = segment.match(/^\{(-?\d+)\}\s*([\s\S]*)$/);

    if (exact !== null) {
        return Number(exact[1]) === count ? exact[2] : null;
    }

    const range = segment.match(/^\[(-?\d+|\*),\s*(-?\d+|\*)\]\s*([\s\S]*)$/);

    if (range === null) {
        return null;
    }

    const [, from, to, line] = range;
    const fitsFrom = from === '*' || count >= Number(from);
    const fitsTo = to === '*' || count <= Number(to);

    return fitsFrom && fitsTo ? line : null;
}

/**
 * Laravel's `trans_choice`: `"one|many"`, or explicit `{0} …|[1,4] …|[5,*] …` segments. `:count`
 * is replaced with the count. Czech has three forms (1, 2–4, 5+), English two.
 */
export function tc(
    key: string,
    count: number,
    replacements: Replacements = {},
): string {
    const segments = (dictionary()[key] ?? key).split('|');
    const all = { count, ...replacements };

    for (const segment of segments) {
        const line = explicitSegment(segment.trim(), count);

        if (line !== null) {
            return replace(line, all);
        }
    }

    const locale = Object.hasOwn(dictionary(), key) ? currentLocale() : 'en';
    const index = Math.min(pluralIndex(locale, count), segments.length - 1);

    return replace(
        segments[index].replace(/^(\{-?\d+\}|\[[^\]]*\])\s*/, ''),
        all,
    );
}
