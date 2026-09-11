/** A project a notification's task lives in — only the ones this reader can open. */
export type InboxProject = {
    id: string;
    name: string;
    color: string | null;
};

/**
 * One line of somebody's inbox.
 *
 * `subject` is null when the thing it was about has since been removed — a notification outlives
 * what it points at, and the screen says so rather than linking nowhere (TASK-130-008).
 */
export type InboxNotification = {
    id: string;
    type: 'task.assigned' | 'comment.posted' | 'comment.mentioned' | 'unknown';
    createdAt: string | null;
    readAt: string | null;
    read: boolean;
    actor: { id: number; name: string; email: string; avatar: string | null } | null;
    /** What a comment said, as it reads now. Null for anything that is not a comment, a comment
     *  since deleted, or a task this reader can no longer reach. */
    excerpt: string | null;
    subject: {
        type: 'task';
        id: string;
        title: string;
        /** Null where this reader can no longer reach it: a link they cannot follow is worse
         *  than a sentence they can still read. */
        url: string | null;
        projects: InboxProject[];
    } | null;
};
