export type TaskAssignee = {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
};

/** A word a workspace uses for its work, with the accent it is drawn in. */
export type TaskTag = {
    id: string;
    name: string;
    color: string | null;
};

export type TaskRowData = {
    /** The list and the board address a card by its placement; My Tasks has no single one. */
    placementId?: string;
    id: string;
    title: string;
    completedAt: string | null;
    dueAt: string | null;
    priority: string;
    /** Removed comments are not counted: the thread shows them, the card does not. */
    comments: number;
    /** What the task is about, drawn as chips (TASK-140-008). */
    tags: TaskTag[];
    /**
     * This row's answers, keyed by field id (TASK-150-008). Keyed rather than positional: a
     * project whose fields changed between two requests would otherwise shift every row's
     * values sideways.
     */
    fields?: Record<string, string | number | boolean>;
    assignee: TaskAssignee | null;
};

/**
 * A column of the list. A null id is the ungrouped bucket — tasks in the project and in no
 * column — which is a place rather than an absence.
 */
/** A column of the list: one of the project's custom fields. */
export type ListFieldColumn = {
    id: string;
    name: string;
    type: 'text' | 'number' | 'date' | 'boolean' | 'select';
};

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

/** What may be done to a project's columns, decided by the server (ADR-0010). */
export type SectionAbilities = {
    createSection: boolean;
    updateSection: boolean;
    deleteSection: boolean;
};

export type ProjectList = {
    sections: TaskSectionGroup[];
    /** The project's fields, once — each row answers them by id. */
    fields: ListFieldColumn[];
    can: TaskAbilities & SectionAbilities;
};

export type BoardCardData = TaskRowData & {
    placementId: string;
    subtasks: number;
};

/**
 * A My Tasks row: the list view's row, plus where the task lives. The projects are only the
 * ones this reader can reach — the server decides that, not the screen.
 */
export type MyTaskRow = TaskRowData & {
    projects: { id: string; name: string; color: string | null }[];
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
    can: TaskAbilities & SectionAbilities;
};

export type TaskDetailPlacement = {
    placementId: string;
    project: { id: string; name: string; color: string | null; archived: boolean };
    section: { id: string; name: string } | null;
    /** The columns this project offers, so the panel can move the task without a second read. */
    sections: { id: string; name: string }[];
    /** Moving and detaching are one permission — see `TaskProjectMembershipPolicy`. */
    canChange: boolean;
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
    /** The fields this task's projects record, with this task's answers (TASK-150-007). */
    customFields: TaskCustomField[];
    tags: TaskTag[];
    /** The workspace's whole vocabulary, small enough to send whole. */
    availableTags: TaskTag[];
    attachments: TaskAttachment[];
    can: { update: boolean; delete: boolean; comment: boolean; attach: boolean };
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

/**
 * A file hanging from a task. There is no path here: it is generated, it is nobody's business
 * outside its table, and the download goes through an endpoint that asks a question first.
 */
export type TaskAttachment = {
    id: string;
    name: string;
    size: number;
    mimeType: string;
    uploadedAt: string | null;
    uploader: TaskAssignee | null;
    canDelete: boolean;
};

/**
 * A field one of the task's projects records, and this task's answer to it.
 *
 * `value` is already the shape its type wants — a date as `YYYY-MM-DD`, a choice as an option id
 * — because the server knows which column it came out of and the screen does not.
 */
export type TaskCustomField = {
    id: string;
    name: string;
    type: 'text' | 'number' | 'date' | 'boolean' | 'select';
    options: { id: string; label: string; color: string | null }[];
    value: string | number | boolean | null;
};

export type TaskFeed = {
    entries: TaskFeedEntry[];
    meta: { page: number; perPage: number; total: number; hasMore: boolean };
};
