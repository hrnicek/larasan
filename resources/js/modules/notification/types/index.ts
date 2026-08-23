/**
 * One line of somebody's inbox.
 *
 * `subject` is null when the thing it was about has since been removed — a notification outlives
 * what it points at, and the screen says so rather than linking nowhere (TASK-130-008).
 */
export type InboxNotification = {
    id: string;
    type: 'task.assigned' | 'comment.posted' | 'unknown';
    createdAt: string | null;
    readAt: string | null;
    read: boolean;
    actor: { id: number; name: string; email: string } | null;
    subject: { type: 'task'; id: string; title: string } | null;
};
