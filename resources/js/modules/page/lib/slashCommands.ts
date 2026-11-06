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
import { t } from '@/lib/i18n';
import type { SlashCommand } from '@/modules/page/components/PageSlashMenu.vue';

type SlashCommandDefinition = SlashCommand & {
    aliases: string[];
    run: (editor: Editor, range: Range) => void;
};

export const slashCommands = (): SlashCommandDefinition[] => [
    {
        key: 'paragraph',
        label: t('Text'),
        hint: t('Plain paragraph'),
        icon: Type,
        aliases: ['text', 'paragraph', 'plain'],
        run: (editor, range) =>
            editor.chain().focus().deleteRange(range).setParagraph().run(),
    },
    {
        key: 'heading-1',
        label: t('Heading 1'),
        hint: t('Section title'),
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
        label: t('Heading 2'),
        hint: t('Subsection'),
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
        label: t('Heading 3'),
        hint: t('Smaller subsection'),
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
        label: t('Bulleted list'),
        hint: t('Items in no order'),
        icon: List,
        aliases: ['bullet', 'unordered', 'ul'],
        run: (editor, range) =>
            editor.chain().focus().deleteRange(range).toggleBulletList().run(),
    },
    {
        key: 'ordered-list',
        label: t('Numbered list'),
        hint: t('Items in order'),
        icon: ListOrdered,
        aliases: ['number', 'ordered', 'ol'],
        run: (editor, range) =>
            editor.chain().focus().deleteRange(range).toggleOrderedList().run(),
    },
    {
        key: 'task-list',
        label: t('To-do list'),
        hint: t('Items to tick off'),
        icon: ListTodo,
        aliases: ['todo', 'task', 'checkbox'],
        run: (editor, range) =>
            editor.chain().focus().deleteRange(range).toggleTaskList().run(),
    },
    {
        key: 'blockquote',
        label: t('Quote'),
        hint: t('Somebody else’s words'),
        icon: Quote,
        aliases: ['quote', 'blockquote'],
        run: (editor, range) =>
            editor.chain().focus().deleteRange(range).toggleBlockquote().run(),
    },
    {
        key: 'code-block',
        label: t('Code block'),
        hint: t('Code, kept as written'),
        icon: Code,
        aliases: ['code', 'snippet'],
        run: (editor, range) =>
            editor.chain().focus().deleteRange(range).toggleCodeBlock().run(),
    },
    {
        key: 'table',
        label: t('Table'),
        hint: t('Three columns to start'),
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
        label: t('Divider'),
        hint: t('A line between things'),
        icon: Minus,
        aliases: ['divider', 'rule', 'hr', 'separator'],
        run: (editor, range) =>
            editor.chain().focus().deleteRange(range).setHorizontalRule().run(),
    },
];

export function matchingCommands(query: string): SlashCommandDefinition[] {
    const term = query.trim().toLowerCase();

    const commands = slashCommands();

    if (term === '') {
        return commands;
    }

    return commands.filter(
        (command) =>
            command.label.toLowerCase().includes(term) ||
            command.aliases.some((alias) => alias.includes(term)),
    );
}
