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
