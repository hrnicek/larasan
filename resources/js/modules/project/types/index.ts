export type ProjectSummary = {
    id: string;
    name: string;
    slug: string;
    color: string | null;
    visibility: string;
};

export type ProjectSettings = ProjectSummary & {
    description: string | null;
    icon: string | null;
    default_view: string;
    start_date: string | null;
    due_date: string | null;
    archived: boolean;
};

export type ProjectOptions = {
    colors: string[];
    views: string[];
    visibilities: string[];
};

export type ProjectAbilities = {
    update: boolean;
    archive: boolean;
    delete: boolean;
    manageMembers: boolean;
};
