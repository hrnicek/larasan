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
