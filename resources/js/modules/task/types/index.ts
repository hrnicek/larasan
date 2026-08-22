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
