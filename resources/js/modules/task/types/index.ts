export type TaskAssignee = {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
};

export type TaskRowData = {
    placementId: string;
    id: string;
    title: string;
    completedAt: string | null;
    dueAt: string | null;
    priority: string;
    /** Removed comments are not counted: the thread shows them, the card does not. */
    comments: number;
    assignee: TaskAssignee | null;
};

/**
 * A column of the list. A null id is the ungrouped bucket — tasks in the project and in no
 * column — which is a place rather than an absence.
 */
export type TaskSectionGroup = {
    id: string | null;
    name: string | null;
    color: string | null;
    count: number;
    tasks: TaskRowData[];
};

export type TaskAbilities = {
    createTask: boolean;
    updateTask: boolean;
    deleteTask: boolean;
};

export type ProjectList = {
    sections: TaskSectionGroup[];
    can: TaskAbilities;
};

export type BoardCardData = TaskRowData & {
    subtasks: number;
};

/**
 * A column of the board. `count` is the whole column and `tasks` is the page drawn from it,
 * so `hasMore` is the server's statement rather than something the client infers.
 */
export type BoardColumnData = {
    id: string | null;
    name: string | null;
    color: string | null;
    count: number;
    hasMore: boolean;
    tasks: BoardCardData[];
};

export type ProjectBoard = {
    columns: BoardColumnData[];
    perColumn: number;
    can: TaskAbilities;
};

export type TaskDetailPlacement = {
    placementId: string;
    project: { id: string; name: string; color: string | null; archived: boolean };
    section: { id: string; name: string } | null;
    canDetach: boolean;
};

export type TaskDetail = {
    availableProjects: { id: string; name: string }[];
    followers: TaskAssignee[];
    following: boolean;
    task: {
        id: string;
        title: string;
        description: string | null;
        priority: string;
        dueAt: string | null;
        completedAt: string | null;
        parent: { id: string; title: string } | null;
        assignee: TaskAssignee | null;
        creator: TaskAssignee | null;
    };
    placements: TaskDetailPlacement[];
    subtasks: { id: string; title: string; completedAt: string | null }[];
    can: { update: boolean; delete: boolean; comment: boolean };
};

/**
 * One line of a task's thread — something somebody said, or something that happened.
 *
 * `canEdit` and `canDelete` come from the server (`TaskFeedQuery`). Deriving them here would
 * put a permission rule in a template and let the two answers drift.
 */
export type TaskFeedEntry = {
    id: string;
    kind: 'comment' | 'activity';
    createdAt: string;
    actor: TaskAssignee | null;
    /** Null on an activity, and on a comment that was removed. */
    body: string | null;
    edited: boolean;
    deleted: boolean;
    /** The activity's type, e.g. `task.completed`. Null on a comment. */
    type: string | null;
    properties: Record<string, unknown> | null;
    canEdit: boolean;
    canDelete: boolean;
};

export type TaskFeed = {
    entries: TaskFeedEntry[];
    meta: { page: number; perPage: number; total: number; hasMore: boolean };
};
