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
 *
 * Every width is `md:`-scoped, and that is not a detail. Below `md` the row *is* two lines and
 * the cells are no longer columns; a 160px assignee and a 96px priority on a 375px line push the
 * last one off the screen — which is exactly what they did before this was scoped.
 */
export const listColumns = {
    assignee: 'md:w-40 md:shrink-0',
    due: 'md:w-20 md:shrink-0',
    priority: 'md:w-24 md:shrink-0',
    /** One custom field's answer. Narrow on purpose: a project can attach several. */
    field: 'truncate md:w-24 md:shrink-0',
} as const;
