/**
 * The list's column widths, in one place.
 *
 * A header row and the rows under it are two components, and a column header that has drifted
 * from the cell below it is worse than no header at all — it labels the wrong thing with
 * confidence. Both read these, so they cannot disagree.
 *
 * Fixed widths rather than a grid: the rows are rendered per section, so a `grid` would have to
 * span components that are siblings, and a table would give up the row's ability to reflow onto
 * two lines below `md`.
 */
export const listColumns = {
    assignee: 'w-40 shrink-0',
    due: 'w-20 shrink-0',
    priority: 'w-24 shrink-0',
    /** One custom field's answer. Narrow on purpose: a project can attach several. */
    field: 'w-24 shrink-0 truncate',
} as const;
