// Class names are written out in full because Tailwind cannot detect interpolated ones.
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

// A hex colour is drawn through `--custom-accent` and the `accent-*` rules in app.css. See ADR-0021.
export function isCustomAccent(color: string | null): color is string {
    return typeof color === 'string' && color.startsWith('#');
}

export function accentVars(color: string | null): Record<string, string> | undefined {
    return isCustomAccent(color) ? { '--custom-accent': color } : undefined;
}

function named(record: Record<AccentColor, string>, color: string | null, fallback: string): string {
    return record[color as AccentColor] ?? fallback;
}

export function accentTextClass(color: string | null): string {
    if (isCustomAccent(color)) {
        return 'accent-text';
    }

    return named(accentTextClasses, color, accentTextClasses.slate);
}

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

export const accentColorNames = Object.keys(accentDotClasses) as AccentColor[];

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

// The tiles above assume the sidebar rail, which is dark in both themes; these follow the page theme.
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
