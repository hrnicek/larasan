const pad = (value: number): string => String(value).padStart(2, '0');

export function today(): string {
    const now = new Date();

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

export function dayOf(dueAt: string | null): string | null {
    return dueAt === null ? null : dueAt.slice(0, 10);
}

export function isOverdue(day: string | null): boolean {
    return day !== null && day < today();
}

export function formatDay(day: string): string {
    const [year, month, date] = day.split('-').map(Number);

    return new Date(year, month - 1, date).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
}
