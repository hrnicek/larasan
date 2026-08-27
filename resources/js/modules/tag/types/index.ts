/**
 * A tag as the settings screen reads it: the word, its accent, and how much work carries it.
 *
 * `TaskTag` in `modules/task/types` is the same row without the count — the picker on a task has
 * no use for it, and the payload it arrives in is sent for every task on the screen.
 */
export type WorkspaceTag = {
    id: string;
    name: string;
    color: string | null;
    taskCount: number;
};
