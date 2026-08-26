/**
 * The list's columns, in one place.
 *
 * A header row and the rows under it are two components, and a column header that has drifted
 * from the cell below it is worse than no header at all — it labels the wrong thing with
 * confidence. Both read these, so they cannot disagree.
 *
 * Fixed widths rather than a grid: the rows are rendered per section, so a `grid` would have to
 * span components that are siblings, and a table would give up the row's ability to reflow onto
 * two lines below `md`.
 *
 * What the name cell gives its room to, it gives in this order: the title first, down to a floor
 * (`TaskTextField`), then the comment count. The cell is a container query container, so the floor
 * reads its width rather than the window's — a project's custom field columns decide how much of
 * the window the name ever sees. Tags were drawn here too until they were taken out of the row
 * (TASK-230-010); the floor is what is left of that, and it is what stops a title being measured
 * at zero by whatever ends up beside it next.
 *
 * Every width is `md:`-scoped, and that is not a detail. Below `md` the row *is* two lines and
 * the cells are no longer columns; a 176px assignee and a 112px priority on a 375px line push the
 * last one off the screen — which is exactly what they did before this was scoped.
 */
export const listColumns = {
    /** The row's place in its section. Narrow, right-aligned, a column of its own so it lines up. */
    index: 'md:w-10 md:shrink-0 md:justify-end md:pr-2',
    /**
     * The name takes what is left, but never less than this: a name cell squeezed to a few
     * characters by a project with several field columns is a list nobody can read. The floor is
     * lower between `md` and `lg`, where the canvas itself is narrow enough that holding 20rem
     * would push the last column off the screen instead.
     */
    name: 'min-w-0 flex-1 md:min-w-64 lg:min-w-80',
    assignee: 'md:w-44 md:shrink-0',
    due: 'md:w-28 md:shrink-0',
    priority: 'md:w-28 md:shrink-0',
    /**
     * One custom field's answer. Narrow on purpose: a project can attach several. The cell is a
     * flex row, where `truncate` clips without ever drawing the ellipsis it is asked for, so the
     * text inside it carries that class instead — here it would only hide the overflow.
     */
    field: 'md:w-32 md:shrink-0',
    /** The empty column that carries the table to the right edge of the page. */
    filler: 'hidden md:block md:w-12 md:shrink-0',
    /** What makes a cell a cell: the line to its right and the room inside it. */
    cell: 'md:border-r md:border-border/70 md:px-3 md:py-1.5',
    /** A cell answers the pointer itself, so it is clear which one a click would land in. */
    hover: 'transition-colors md:hover:bg-accent/40 md:hover:ring-1 md:hover:ring-border md:hover:ring-inset',
} as const;

/**
 * One column the list draws, as the server orders them.
 *
 * `kind` is what to draw, `key` is what identifies it in the stored order, `label` is the header,
 * and `type` is a field's — it is what lets a row draw a tick for a boolean rather than the word
 * `true`. The task name is not one of these: it is always the first column.
 */
export type ListColumn = {
    key: string;
    kind: 'field' | 'assignee' | 'due' | 'priority';
    label: string;
    type: string | null;
};

/**
 * What a list draws when nobody has ordered it — My Tasks and the search results, which belong to
 * no project and so have nothing to reorder.
 */
export const defaultListColumns: ListColumn[] = [
    { key: 'assignee', kind: 'assignee', label: 'Assignee', type: null },
    { key: 'due', kind: 'due', label: 'Due', type: null },
    { key: 'priority', kind: 'priority', label: 'Priority', type: null },
];

/** The width a column of this kind takes. */
export function widthFor(kind: ListColumn['kind']): string {
    return kind === 'field' ? listColumns.field : listColumns[kind];
}
