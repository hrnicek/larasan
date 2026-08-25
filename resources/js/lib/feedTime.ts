/**
 * A moment in a task's thread, drawn the way a person would say it.
 *
 * Relative while it is still recent, because "12 minutes ago" is what somebody reading a live
 * conversation wants; a date once it is not, because "43 days ago" is arithmetic rather than an
 * answer. The reader's own locale and time zone decide how both are written.
 */
const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ['second', 60],
    ['minute', 60],
    ['hour', 24],
];

export function formatFeedTime(iso: string): string {
    const at = new Date(iso);

    if (Number.isNaN(at.getTime())) {
        return iso;
    }

    const seconds = Math.round((at.getTime() - Date.now()) / 1000);
    let value = seconds;

    for (const [unit, span] of units) {
        if (Math.abs(value) < span) {
            return new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' }).format(
                Math.round(value),
                unit,
            );
        }

        value /= span;
    }

    // Past a day it is a date. The year only when it is not this one — a thread mostly reads
    // within the year it happened in, and repeating it on every line says nothing.
    return at.toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: at.getFullYear() === new Date().getFullYear() ? undefined : 'numeric',
    });
}

/** The full moment, for the title attribute — the detail the short form leaves out. */
export function fullFeedTime(iso: string): string {
    const at = new Date(iso);

    return Number.isNaN(at.getTime()) ? iso : at.toLocaleString();
}
