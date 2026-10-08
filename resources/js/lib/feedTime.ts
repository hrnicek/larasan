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
            return new Intl.RelativeTimeFormat(undefined, {
                numeric: 'auto',
            }).format(Math.round(value), unit);
        }

        value /= span;
    }

    return at.toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year:
            at.getFullYear() === new Date().getFullYear()
                ? undefined
                : 'numeric',
    });
}

export function fullFeedTime(iso: string): string {
    const at = new Date(iso);

    return Number.isNaN(at.getTime()) ? iso : at.toLocaleString();
}
