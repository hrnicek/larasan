import { TaskItem, TaskList } from '@tiptap/extension-list';
import { Placeholder } from '@tiptap/extension-placeholder';
import {
    Table,
    TableCell,
    TableHeader,
    TableRow,
} from '@tiptap/extension-table';
import StarterKit from '@tiptap/starter-kit';
import type { Extensions } from '@tiptap/vue-3';

// Must match the server's `PageDocument` vocabulary: anything it does not know is dropped on save.
export function pageExtensions(placeholder: string): Extensions {
    return [
        StarterKit.configure({
            heading: { levels: [1, 2, 3] },

            // `openOnClick` would follow a link while its text is being edited. Schemes are filtered server-side.
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
