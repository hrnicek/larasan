import { TaskItem, TaskList } from '@tiptap/extension-list';
import { Placeholder } from '@tiptap/extension-placeholder';
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';
import StarterKit from '@tiptap/starter-kit';
import type { Extensions } from '@tiptap/vue-3';

/**
 * The vocabulary a page may be written in.
 *
 * It is deliberately the same list the server keeps in `PageDocument`, and the two are allowed
 * to disagree in exactly one direction: anything the editor can produce that the server does not
 * know is dropped on the way in, so a mismatch costs a paragraph rather than a security hole.
 * Adding a block here without adding it there means writing something that vanishes on save.
 *
 * Headings stop at three because that is where the design system stops drawing them
 * (`DESIGN.md`): a fourth level would render as body text with extra weight, which is a heading
 * that does not look like one.
 */
export function pageExtensions(placeholder: string): Extensions {
    return [
        StarterKit.configure({
            heading: { levels: [1, 2, 3] },

            // `openOnClick` would follow a link while somebody is trying to edit its text. The
            // rest of the mark's safety is the server's: `PageDocument` decides which schemes
            // survive, and rel/target are added where the link is drawn.
            link: { openOnClick: false, autolink: true },
        }),

        TaskList,
        TaskItem.configure({ nested: true }),

        Table.configure({ resizable: true }),
        TableRow,
        TableHeader,
        TableCell,

        Placeholder.configure({ placeholder }),
    ];
}
