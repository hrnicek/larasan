/**
 * A due date is a day, and a day is `YYYY-MM-DD`.
 *
 * That shape sorts, compares and prints without a date library, which is the point: the picker's
 * month grid needs `@internationalized/date` and every row of a list does not, so the library is
 * loaded with the grid (`DueDateCalendar.vue`) and these three lines serve everywhere else.
 */

const pad = (value: number): string => String(value).padStart(2, '0');

/** The reader's own today, in the shape a due date is stored in. */
export function today(): string {
    const now = new Date();

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/** The day out of a stored `due_at`, which may carry a time this product cannot set. */
export function dayOf(dueAt: string | null): string | null {
    return dueAt === null ? null : dueAt.slice(0, 10);
}

/** Compared as days rather than instants: a task due today is not late at nine in the morning. */
export function isOverdue(day: string | null): boolean {
    return day !== null && day < today();
}

/** The reader's own locale, short: `12 Aug` is a date, `2026-08-12` is a value. */
export function formatDay(day: string): string {
    const [year, month, date] = day.split('-').map(Number);

    return new Date(year, month - 1, date).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
}
