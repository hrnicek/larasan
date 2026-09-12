import type { CustomFieldType } from '@/modules/custom-field/types';
import type { ListColumn } from '@/modules/task/listColumns';

export type TaskAssignee = {
    id: number;
    name: string;
    /** Absent for a guest reader. */
    email?: string;
    avatar: string | null;
};

export type TaskTag = {
    id: string;
    name: string;
    color: string | null;
};

export type TaskRowData = {
    /** Absent on My Tasks, where a task has no single placement. */
    placementId?: string;
    id: string;
    title: string;
    completedAt: string | null;
    dueAt: string | null;
    priority: string;
    /** Excludes removed comments. */
    comments: number;
    tags: TaskTag[];
    /** Keyed by custom field id. */
    fields?: Record<string, string | number | boolean>;
    /** First image attachment, board only; dimensions are null until the thumbnail exists. */
    cover?: { id: string; width: number | null; height: number | null } | null;
    assignee: TaskAssignee | null;
};

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

export type SectionAbilities = {
    createSection: boolean;
    updateSection: boolean;
    deleteSection: boolean;
};

export type ProjectList = {
    sections: TaskSectionGroup[];
    fields: ListFieldColumn[];
    columns: ListColumn[];
    can: TaskAbilities & SectionAbilities;
};

export type BoardCardData = TaskRowData & {
    placementId: string;
    subtasks: number;
};

export type MyTaskRow = TaskRowData & {
    projects: { id: string; name: string; color: string | null }[];
    /** Per row, because My Tasks and search mix tasks from many projects. */
    canUpdate: boolean;
};

/** `count` covers the whole column; `tasks` is only the loaded page. */
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

/** `count` covers the whole day; `tasks` is only the loaded page. */
export type CalendarDay = {
    date: string;
    inMonth: boolean;
    count: number;
    hasMore: boolean;
    tasks: CalendarCardData[];
};

export type ProjectCalendar = {
    /** `YYYY-MM`. */
    month: string;
    /** The server's current date, `Y-m-d`. */
    today: string;
    perDay: number;
    days: CalendarDay[];
    undated: { count: number; hasMore: boolean; tasks: CalendarCardData[] };
    can: TaskAbilities;
};

export type TaskDetailPlacement = {
    placementId: string;
    project: { id: string; name: string; color: string | null; archived: boolean };
    section: { id: string; name: string } | null;
    sections: { id: string; name: string }[];
    /** Covers both moving and detaching. */
    canChange: boolean;
};

export type TaskDetail = {
    availableProjects: { id: string; name: string }[];
    collaborators: TaskAssignee[];
    collaborating: boolean;
    followers: TaskAssignee[];
    following: boolean;
    /** The current user's star, not a task property. */
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
    customFields: TaskCustomField[];
    tags: TaskTag[];
    availableTags: TaskTag[];
    attachments: TaskAttachment[];
    can: { update: boolean; assign: boolean; delete: boolean; comment: boolean; attach: boolean; manageTags: boolean };
};

export type TaskFeedEntry = {
    id: string;
    kind: 'comment' | 'activity';
    createdAt: string;
    actor: TaskAssignee | null;
    /** Null on activities and removed comments. */
    body: string | null;
    edited: boolean;
    deleted: boolean;
    /** Activity type, e.g. `task.completed`; null on comments. */
    type: string | null;
    properties: Record<string, unknown> | null;
    canEdit: boolean;
    canDelete: boolean;
};

export type TaskAttachment = {
    id: string;
    name: string;
    size: number;
    mimeType: string;
    /** Derived from the detected MIME type, e.g. `image`, `pdf`. */
    kind: string;
    /** Null until the thumbnail has been generated. */
    image: { width: number; height: number } | null;
    uploadedAt: string | null;
    uploader: TaskAssignee | null;
    canDelete: boolean;
};

/** `value` arrives typed: a date as `YYYY-MM-DD`, a choice as an option id. */
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
