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
