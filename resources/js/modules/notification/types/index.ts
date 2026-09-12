/** Only projects the reader can open. */
export type InboxProject = {
    id: string;
    name: string;
    color: string | null;
};

/** `subject` is null once the task has been removed. */
export type InboxNotification = {
    id: string;
    type: 'task.assigned' | 'task.collaborator_added' | 'comment.posted' | 'comment.mentioned' | 'unknown';
    createdAt: string | null;
    readAt: string | null;
    read: boolean;
    actor: { id: number; name: string; email: string; avatar: string | null } | null;
    /** Null unless it is a comment that still exists on a task the reader can reach. */
    excerpt: string | null;
    subject: {
        type: 'task';
        id: string;
        title: string;
        /** Null where the reader can no longer reach the task. */
        url: string | null;
        projects: InboxProject[];
    } | null;
};
