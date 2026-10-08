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

/** Managers only. */
export type WorkspacePendingInvitation = {
    id: string;
    email: string;
    /** Null until an account holds the invitation. */
    name: string | null;
    role: string;
    invitedBy: string | null;
    expiresAt: string | null;
    hasExpired: boolean;
    hasAccount: boolean;
};

export type WorkspaceInvitation = {
    id: string;
    /** Only the name, since an invitee is not yet a member. */
    workspace: string;
    role: string;
    invitedBy: string | null;
    expiresAt: string | null;
    hasExpired: boolean;
};
