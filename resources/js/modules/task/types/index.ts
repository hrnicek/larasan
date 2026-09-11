import type { CustomFieldType } from '@/modules/custom-field/types';
import type { ListColumn } from '@/modules/task/listColumns';

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
    /**
     * The first image attached to the task, by the order somebody put its files in
     * (TASK-250-005). Only the board fills this in; the list, My Tasks and the calendar share
     * this shape and draw no picture. The dimensions are absent until the thumbnail exists.
     */
    cover?: { id: string; width: number | null; height: number | null } | null;
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
    type: CustomFieldType;
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
    /** The project's fields, once — each row answers them by id. What the sort control offers. */
    fields: ListFieldColumn[];
    /**
     * The columns after the name, in the order this project draws them (TASK-240-010). The header
     * and the rows read the same list, so they cannot disagree about which column is which.
     */
    columns: ListColumn[];
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
    /**
     * Whether this row may be edited, answered per row rather than per screen. My Tasks and
     * search draw work from every board at once, so one flag for the list would promise an edit
     * the board behind a given row refuses (TASK-260-001).
     */
    canUpdate: boolean;
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

/**
 * A chip on the calendar: less than a board card carries, because a day cell is read at a glance
 * and in bulk. No comment count and no field answers — what a cell has room for is who has it,
 * what it is about and whether it is done.
 */
export type CalendarCardData = {
    placementId: string;
    id: string;
    title: string;
    completedAt: string | null;
    dueAt: string | null;
    priority: string;
    tags: TaskTag[];
    assignee: TaskAssignee | null;
};

/**
 * One cell of the grid. `count` is every task due that day and `tasks` is the page drawn from
 * it, so `hasMore` is the server's statement rather than something the cell infers from a size.
 */
export type CalendarDay = {
    date: string;
    /** False for the days that spill in from the months on either side. */
    inMonth: boolean;
    count: number;
    hasMore: boolean;
    tasks: CalendarCardData[];
};

export type ProjectCalendar = {
    /** The month being drawn, as `YYYY-MM` — the same value the URL carries. */
    month: string;
    /** The server's today, so the ringed cell and the *Today* control agree on which day it is. */
    today: string;
    perDay: number;
    days: CalendarDay[];
    /** Work nobody has scheduled: counted, and offered as a tray rather than hidden. */
    undated: { count: number; hasMore: boolean; tasks: CalendarCardData[] };
    can: TaskAbilities;
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
    /** The people working on the task beside its assignee (TASK-310-004). */
    collaborators: TaskAssignee[];
    /** Whether this reader is one of them — the server's answer, like `following`. */
    collaborating: boolean;
    followers: TaskAssignee[];
    following: boolean;
    /** This reader's own star, not a fact about the task: two people get two answers. */
    starred: boolean;
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
    can: { update: boolean; assign: boolean; delete: boolean; comment: boolean; attach: boolean; manageTags: boolean };
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
    /** The server's reading of the MIME type — `image`, `pdf`, `document`… — never re-derived here. */
    kind: string;
    /** The picture's own shape, for the tile to reserve. Null until its thumbnail has been made. */
    image: { width: number; height: number } | null;
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
    type: CustomFieldType;
    options: { id: string; label: string; color: string | null }[];
    value: string | number | boolean | null;
};

export type TaskFeed = {
    entries: TaskFeedEntry[];
    meta: { page: number; perPage: number; total: number; hasMore: boolean };
};
