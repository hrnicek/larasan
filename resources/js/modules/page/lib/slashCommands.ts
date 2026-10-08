import {
    Code,
    Heading1,
    Heading2,
    Heading3,
    List,
    ListOrdered,
    ListTodo,
    Minus,
    Quote,
    Table as TableIcon,
    Type,
} from '@lucide/vue';
import type { Editor, Range } from '@tiptap/vue-3';
import type { SlashCommand } from '@/modules/page/components/PageSlashMenu.vue';

type SlashCommandDefinition = SlashCommand & {
    aliases: string[];
    run: (editor: Editor, range: Range) => void;
};

export const slashCommands: SlashCommandDefinition[] = [
    {
        key: 'paragraph',
        label: 'Text',
        hint: 'Plain paragraph',
        icon: Type,
        aliases: ['text', 'paragraph', 'plain'],
        run: (editor, range) =>
            editor.chain().focus().deleteRange(range).setParagraph().run(),
    },
    {
        key: 'heading-1',
        label: 'Heading 1',
        hint: 'Section title',
        icon: Heading1,
        aliases: ['h1', 'title', 'heading'],
        run: (editor, range) =>
            editor
                .chain()
                .focus()
                .deleteRange(range)
                .setNode('heading', { level: 1 })
                .run(),
    },
    {
        key: 'heading-2',
        label: 'Heading 2',
        hint: 'Subsection',
        icon: Heading2,
        aliases: ['h2', 'heading'],
        run: (editor, range) =>
            editor
                .chain()
                .focus()
                .deleteRange(range)
                .setNode('heading', { level: 2 })
                .run(),
    },
    {
        key: 'heading-3',
        label: 'Heading 3',
        hint: 'Smaller subsection',
        icon: Heading3,
        aliases: ['h3', 'heading'],
        run: (editor, range) =>
            editor
                .chain()
                .focus()
                .deleteRange(range)
                .setNode('heading', { level: 3 })
                .run(),
    },
    {
        key: 'bullet-list',
        label: 'Bulleted list',
        hint: 'Items in no order',
        icon: List,
        aliases: ['bullet', 'unordered', 'ul'],
        run: (editor, range) =>
            editor.chain().focus().deleteRange(range).toggleBulletList().run(),
    },
    {
        key: 'ordered-list',
        label: 'Numbered list',
        hint: 'Items in order',
        icon: ListOrdered,
        aliases: ['number', 'ordered', 'ol'],
        run: (editor, range) =>
            editor.chain().focus().deleteRange(range).toggleOrderedList().run(),
    },
    {
        key: 'task-list',
        label: 'To-do list',
        hint: 'Items to tick off',
        icon: ListTodo,
        aliases: ['todo', 'task', 'checkbox'],
        run: (editor, range) =>
            editor.chain().focus().deleteRange(range).toggleTaskList().run(),
    },
    {
        key: 'blockquote',
        label: 'Quote',
        hint: 'Somebody else’s words',
        icon: Quote,
        aliases: ['quote', 'blockquote'],
        run: (editor, range) =>
            editor.chain().focus().deleteRange(range).toggleBlockquote().run(),
    },
    {
        key: 'code-block',
        label: 'Code block',
        hint: 'Code, kept as written',
        icon: Code,
        aliases: ['code', 'snippet'],
        run: (editor, range) =>
            editor.chain().focus().deleteRange(range).toggleCodeBlock().run(),
    },
    {
        key: 'table',
        label: 'Table',
        hint: 'Three columns to start',
        icon: TableIcon,
        aliases: ['table', 'grid'],
        run: (editor, range) =>
            editor
                .chain()
                .focus()
                .deleteRange(range)
                .insertTable({ rows: 3, cols: 3, withHeaderRow: true })
                .run(),
    },
    {
        key: 'divider',
        label: 'Divider',
        hint: 'A line between things',
        icon: Minus,
        aliases: ['divider', 'rule', 'hr', 'separator'],
        run: (editor, range) =>
            editor.chain().focus().deleteRange(range).setHorizontalRule().run(),
    },
];

export function matchingCommands(query: string): SlashCommandDefinition[] {
    const term = query.trim().toLowerCase();

    if (term === '') {
        return slashCommands;
    }

    return slashCommands.filter(
        (command) =>
            command.label.toLowerCase().includes(term) ||
            command.aliases.some((alias) => alias.includes(term)),
    );
}
