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

export function accentTextClass(color: string | null): string {
    return accentTextClasses[color as AccentColor] ?? accentTextClasses.slate;
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
    return accentDotClasses[color as AccentColor] ?? accentDotClasses.slate;
}

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
    return accentTileClasses[color as AccentColor] ?? accentTileClasses.slate;
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
    return accentChipClasses[color as AccentColor] ?? accentChipClasses.slate;
}
