import { defineAsyncComponent } from 'vue';

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
