// A mention is stored as `@[Jana Nováková](user:42)` and shown in the textarea as `@Jana Nováková`.
export type NamedPerson = { id: number; name: string };

export type CommentSegment =
    { kind: 'text'; text: string } | ({ kind: 'mention' } & NamedPerson);

const token = (): RegExp => /@\[([^[\]\r\n]{1,120})\]\(user:(\d{1,18})\)/gu;

const escapeForPattern = (value: string): string =>
    value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

export const segmentsOf = (body: string): CommentSegment[] => {
    const segments: CommentSegment[] = [];
    let cursor = 0;

    for (const match of body.matchAll(token())) {
        const start = match.index ?? 0;

        if (start > cursor) {
            segments.push({ kind: 'text', text: body.slice(cursor, start) });
        }

        segments.push({
            kind: 'mention',
            id: Number(match[2]),
            name: match[1],
        });
        cursor = start + match[0].length;
    }

    if (cursor < body.length) {
        segments.push({ kind: 'text', text: body.slice(cursor) });
    }

    return segments;
};

export const toDisplay = (
    body: string,
): { text: string; named: NamedPerson[] } => {
    const named = new Map<number, NamedPerson>();

    const text = body.replace(token(), (_match, name: string, id: string) => {
        named.set(Number(id), { id: Number(id), name });

        return `@${name}`;
    });

    return { text, named: [...named.values()] };
};

// Longest names first so `@Jan Novák` is not claimed by `@Jan`, and only where the name ends.
export const toStorage = (text: string, named: NamedPerson[]): string =>
    [...named]
        .sort((a, b) => b.name.length - a.name.length)
        .reduce(
            (body, person) =>
                body.replace(
                    new RegExp(
                        `@${escapeForPattern(person.name)}(?![\\p{L}\\p{N}\\]])`,
                        'gu',
                    ),
                    `@[${person.name}](user:${person.id})`,
                ),
            text,
        );

export const foldForSearch = (value: string): string =>
    value.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
