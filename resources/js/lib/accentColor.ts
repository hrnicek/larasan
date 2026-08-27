/**
 * The accent palette from `docs/ui/design-system.md`, as a static record: Tailwind's JIT
 * scanner strips interpolated class names, so `text-${color}-600` compiles to nothing.
 * The darker light step and lighter dark step are what keep contrast at WCAG AA on both
 * surfaces — one step for both fails on one of them.
 */
const accentTextClasses = {
    slate: 'text-slate-500 dark:text-slate-400',
    red: 'text-red-600 dark:text-red-400',
    amber: 'text-amber-600 dark:text-amber-400',
    emerald: 'text-emerald-600 dark:text-emerald-400',
    teal: 'text-teal-600 dark:text-teal-400',
    sky: 'text-sky-600 dark:text-sky-400',
    violet: 'text-violet-600 dark:text-violet-400',
    rose: 'text-rose-600 dark:text-rose-400',
} as const;

export type AccentColor = keyof typeof accentTextClasses;

/**
 * A colour somebody chose rather than one of the eight names (ADR-0021).
 *
 * A chosen colour cannot be a class, because the scanner has to see every class written out and
 * a hex is not known until somebody picks it. It is a custom property instead, read by the
 * `accent-*` rules in `app.css`, which derive the same two steps the records below are tuned for
 * by mixing the hue with the theme's own foreground.
 */
export function isCustomAccent(color: string | null): color is string {
    return typeof color === 'string' && color.startsWith('#');
}

/**
 * What a chosen colour needs bound to the element that draws it. Undefined for the eight, so a
 * named colour carries no inline style at all.
 */
export function accentVars(color: string | null): Record<string, string> | undefined {
    return isCustomAccent(color) ? { '--custom-accent': color } : undefined;
}

/**
 * A record entry by name, or the fallback where the name is not one of the eight — including
 * where there is no name at all. Written once because the alternative is the same cast repeated
 * in six places, each of which the compiler is right to complain about.
 */
function named(record: Record<AccentColor, string>, color: string | null, fallback: string): string {
    return record[color as AccentColor] ?? fallback;
}

export function accentTextClass(color: string | null): string {
    if (isCustomAccent(color)) {
        return 'accent-text';
    }

    return named(accentTextClasses, color, accentTextClasses.slate);
}

/**
 * The same palette as a background, for the dot beside a project or a tag. A separate record for
 * the same reason the first one exists: the scanner has to see every class written out.
 *
 * One step for both themes here, unlike the text classes — a filled 10px square is a shape rather
 * than something to read, so it is judged on being visible rather than on contrast with a
 * character's strokes.
 */
const accentDotClasses = {
    slate: 'bg-slate-400',
    red: 'bg-red-500',
    amber: 'bg-amber-500',
    emerald: 'bg-emerald-500',
    teal: 'bg-teal-500',
    sky: 'bg-sky-500',
    violet: 'bg-violet-500',
    rose: 'bg-rose-500',
} as const;

export function accentDotClass(color: string | null): string {
    if (isCustomAccent(color)) {
        return 'accent-dot';
    }

    return named(accentDotClasses, color, accentDotClasses.slate);
}

/**
 * The same palette as a band behind a section's header, at the opacity a full-width row can carry
 * without the rows under it having to compete with it.
 *
 * Unlike every other record here, an unknown name falls through to nothing rather than to slate: a
 * section's colour is nullable and the migration calls that "the neutral default", so an
 * uncoloured column has no band at all instead of a grey one.
 */
const accentBandClasses = {
    slate: 'bg-slate-500/10',
    red: 'bg-red-500/10',
    amber: 'bg-amber-500/10',
    emerald: 'bg-emerald-500/10',
    teal: 'bg-teal-500/10',
    sky: 'bg-sky-500/10',
    violet: 'bg-violet-500/10',
    rose: 'bg-rose-500/10',
} as const;

export function accentBandClass(color: string | null): string {
    if (isCustomAccent(color)) {
        return 'accent-band';
    }

    return named(accentBandClasses, color, '');
}

/**
 * The palette in the order a picker draws it, from the record that already lists it.
 *
 * `ProjectColor` and `TagColor` are the server's copy of the same eight names. A picker offered
 * them as a prop where the page already has one — the settings form does — and reads them here
 * where it does not, rather than a second literal list going stale against this file's classes.
 */
export const accentColorNames = Object.keys(accentDotClasses) as AccentColor[];

/**
 * The palette as a tile: a tinted square carrying the first letter of a name.
 *
 * What the icon rail needs. A 10px dot identifies nothing when the sidebar is collapsed and the
 * label is gone — several projects share a colour, and most keep the default. A letter on a
 * tinted tile is legible at 24px and is the same affordance the workspace switcher already uses.
 */
const accentTileClasses = {
    slate: 'bg-slate-500/20 text-slate-200',
    red: 'bg-red-500/20 text-red-200',
    amber: 'bg-amber-500/20 text-amber-200',
    emerald: 'bg-emerald-500/20 text-emerald-200',
    teal: 'bg-teal-500/20 text-teal-200',
    sky: 'bg-sky-500/20 text-sky-200',
    violet: 'bg-violet-500/20 text-violet-200',
    rose: 'bg-rose-500/20 text-rose-200',
} as const;

export function accentTileClass(color: string | null): string {
    if (isCustomAccent(color)) {
        return 'accent-tile';
    }

    return named(accentTileClasses, color, accentTileClasses.slate);
}

/**
 * The same tile on a page instead of on the chrome.
 *
 * The record above is tuned for the sidebar rail, which stays dark in both themes — its light
 * text on a tint is what a dark rail needs and is close to invisible on a page that follows the
 * theme. This one follows the theme the way the text classes do: a darker step in light, a
 * lighter one in dark. Two records rather than one because the two surfaces genuinely disagree,
 * and one set of classes cannot be right on both.
 */
const accentContentTileClasses = {
    slate: 'bg-slate-500/15 text-slate-700 dark:text-slate-300',
    red: 'bg-red-500/15 text-red-700 dark:text-red-300',
    amber: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    emerald: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    teal: 'bg-teal-500/15 text-teal-700 dark:text-teal-300',
    sky: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    violet: 'bg-violet-500/15 text-violet-700 dark:text-violet-300',
    rose: 'bg-rose-500/15 text-rose-700 dark:text-rose-300',
} as const;

export function accentContentTileClass(color: string | null): string {
    if (isCustomAccent(color)) {
        return 'accent-content-tile';
    }

    return named(accentContentTileClasses, color, accentContentTileClasses.slate);
}

/**
 * The palette as a chip: a tinted pill carrying a word.
 *
 * Pastel rather than saturated, with the text a darker step of the same hue — a row of fully
 * saturated pills competes with the task's own name, which is the thing the card exists to say.
 */
const accentChipClasses = {
    slate: 'bg-slate-500/15 text-slate-700 dark:text-slate-300',
    red: 'bg-red-500/15 text-red-700 dark:text-red-300',
    amber: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    emerald: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    teal: 'bg-teal-500/15 text-teal-700 dark:text-teal-300',
    sky: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    violet: 'bg-violet-500/15 text-violet-700 dark:text-violet-300',
    rose: 'bg-rose-500/15 text-rose-700 dark:text-rose-300',
} as const;

export function accentChipClass(color: string | null): string {
    if (isCustomAccent(color)) {
        return 'accent-chip';
    }

    return named(accentChipClasses, color, accentChipClasses.slate);
}
