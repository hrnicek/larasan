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
