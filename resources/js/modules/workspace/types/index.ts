export type WorkspaceSummary = {
    id: string;
    name: string;
    slug: string;
};

export type WorkspaceSettings = WorkspaceSummary & {
    timezone: string;
};

export type WorkspaceAbilities = {
    update: boolean;
    delete: boolean;
};

export type WorkspaceMember = {
    id: string;
    name: string;
    /** Null unless the viewer manages members, or it is their own row. */
    email: string | null;
    role: string;
    joinedAt: string | null;
    isYou: boolean;
    isLastOwner: boolean;
};

/** A membership that has been offered and not yet answered. Managers only. */
export type WorkspacePendingInvitation = {
    id: string;
    email: string;
    /** The invitee's name once an account holds the invitation, and null while none does. */
    name: string | null;
    role: string;
    invitedBy: string | null;
    expiresAt: string | null;
    hasExpired: boolean;
    hasAccount: boolean;
};

export type WorkspaceInvitation = {
    id: string;
    /** The workspace's name — an invitee is not a member and gets no more of it than that. */
    workspace: string;
    role: string;
    invitedBy: string | null;
    expiresAt: string | null;
    hasExpired: boolean;
};
