/**
 * How a comment names a person: `@[Jana Nováková](user:42)` is what is sent and stored, and the
 * server rewrites the name to the account's own on the way in and on the way out (TASK-280-001,
 * TASK-280-002). The textarea shows `@Jana Nováková`; these functions translate between the two.
 */
export type NamedPerson = { id: number; name: string };

export type CommentSegment =
    { kind: 'text'; text: string } | ({ kind: 'mention' } & NamedPerson);

const token = (): RegExp => /@\[([^[\]\r\n]{1,120})\]\(user:(\d{1,18})\)/gu;

const escapeForPattern = (value: string): string =>
    value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** A stored body as runs of text and names, for drawing without markup. */
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

/** A stored body as the textarea shows it, and who it names. */
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

/**
 * The textarea's text as it is sent: every `@Name` of somebody chosen becomes their token.
 *
 * Longest names first, so `@Jan Novák` is not claimed by a `@Jan` who was also chosen, and only
 * where the name ends — `@Jan` is not a mention inside `@Janet`. An `@` typed without choosing
 * anybody stays text.
 */
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

/** Case and diacritics set aside, so `nova` finds Nováková. */
export const foldForSearch = (value: string): string =>
    value.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
