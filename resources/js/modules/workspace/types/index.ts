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
    email: string;
    role: string;
    status: string;
    joinedAt: string | null;
    isYou: boolean;
    isLastOwner: boolean;
};
