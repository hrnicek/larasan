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

export type ProjectSection = {
    id: string;
    name: string;
    color: string | null;
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
    createSection: boolean;
};

/**
 * One thing hanging off one of the project's tasks.
 *
 * `kind` is the server's reading of the MIME type, not the extension the upload claimed, and
 * `canDelete` is the attachment policy's answer — the table renders both and derives neither.
 */
export type ProjectFile = {
    id: string;
    name: string;
    size: number;
    extension: string;
    kind: string;
    uploader: { id: number; name: string; email: string } | null;
    task: { id: string; title: string } | null;
    attachedAt: string | null;
    canDelete: boolean;
};

export type ProjectFiles = {
    files: ProjectFile[];
    /** `sort` and `direction` are what the server understood, echoed back for the headers. */
    meta: {
        page: number;
        perPage: number;
        total: number;
        hasMore: boolean;
        sort: string;
        direction: string;
    };
};
