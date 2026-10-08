/**
 * Column classes shared by the list header and its rows. Widths are `md:`-scoped because below
 * `md` a row wraps onto two lines and its cells are no longer columns.
 */
export const listColumns = {
    index: 'md:w-10 md:shrink-0 md:justify-end md:pr-2',
    /** Floor is lower below `lg`, where a 20rem minimum would push the last column off screen. */
    name: 'min-w-0 flex-1 md:min-w-64 lg:min-w-80',
    assignee: 'md:w-44 md:shrink-0',
    due: 'md:w-28 md:shrink-0',
    priority: 'md:w-28 md:shrink-0',
    /** A flex row draws no ellipsis, so `truncate` belongs on the text inside the cell. */
    field: 'md:w-32 md:shrink-0',
    filler: 'hidden md:block md:w-12 md:shrink-0',
    cell: 'md:border-r md:border-border/70 md:px-3 md:py-1.5',
    hover: 'transition-colors md:hover:bg-accent/40 md:hover:ring-1 md:hover:ring-border md:hover:ring-inset',
} as const;

/** The task name is always first and not listed; `type` is the custom field type, null otherwise. */
export type ListColumn = {
    key: string;
    kind: 'field' | 'assignee' | 'due' | 'priority';
    label: string;
    type: string | null;
};

/** For lists outside a project (My Tasks, search), which have no stored column order. */
export const defaultListColumns: ListColumn[] = [
    { key: 'assignee', kind: 'assignee', label: 'Assignee', type: null },
    { key: 'due', kind: 'due', label: 'Due', type: null },
    { key: 'priority', kind: 'priority', label: 'Priority', type: null },
];

export function widthFor(kind: ListColumn['kind']): string {
    return kind === 'field' ? listColumns.field : listColumns[kind];
}
