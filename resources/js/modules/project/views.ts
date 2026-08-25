import { defineAsyncComponent } from 'vue';

/**
 * A project has five views and a visit carries the payload of exactly one of them, so the four it
 * did not ask for are fetched the same way the payload is: when somebody goes to them.
 *
 * The list is not here. It is what a project opens on, and a screen does not lazily load itself.
 *
 * `warmView` is what keeps the switch from being a wait: the view switcher calls it when a
 * pointer reaches a tab, which is a moment before the visit it is about to start.
 */
const board = () => import('@/modules/project/components/BoardColumn.vue');
const grid = () => import('@/modules/project/components/CalendarGrid.vue');
const toolbar = () => import('@/modules/project/components/CalendarToolbar.vue');
const files = () => import('@/modules/project/components/FilesTable.vue');
const pages = () => import('@/modules/page/components/PagesTree.vue');

export const BoardColumn = defineAsyncComponent(board);
export const CalendarGrid = defineAsyncComponent(grid);
export const CalendarToolbar = defineAsyncComponent(toolbar);
export const FilesTable = defineAsyncComponent(files);
export const PagesTree = defineAsyncComponent(pages);

const drawnWith: Record<string, (() => Promise<unknown>)[]> = {
    board: [board],
    calendar: [grid, toolbar],
    files: [files],
    pages: [pages],
};

export function warmView(view: string): void {
    drawnWith[view]?.forEach((load) => void load());
}
