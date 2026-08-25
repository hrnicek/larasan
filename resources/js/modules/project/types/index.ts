import type { Component } from 'vue';

export type ProjectSummary = {
    id: string;
    name: string;
    slug: string;
    color: string | null;
    icon: string | null;
    visibility: string;
};

/**
 * A row in the sidebar's project list. Narrower than `ProjectSummary` — the shared prop sends no
 * visibility, because a rail that lists what somebody may see has already answered that question
 * — and wider by the two abilities the row's own context menu renders. Both are the project
 * policy's answers, computed per row on the server; the client renders them and derives neither.
 */
export type SidebarProject = {
    id: string;
    name: string;
    slug: string;
    color: string | null;
    icon: string | null;
    canUpdate: boolean;
    canArchive: boolean;
    /** This reader's own star, not a property of the project: two people see two answers. */
    starred: boolean;
};

/**
 * What the project screen's header renders itself from. `canUpdate` is the project policy's
 * answer, not a role the client read: the header draws the appearance picker only for somebody
 * the server would let use it, and the endpoint authorizes again regardless.
 */
export type ProjectHeading = {
    id: string;
    name: string;
    slug: string;
    color: string | null;
    icon: string | null;
    archived: boolean;
    canUpdate: boolean;
    starred: boolean;
};

export type ProjectSettings = ProjectSummary & {
    description: string | null;
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

/**
 * One entry in the rail beside the project settings cards. The `id` is the card's element id,
 * which is what the rail scrolls to and what its scroll-spy reports back.
 */
export type ProjectSettingsNavItem = {
    id: string;
    label: string;
    icon: Component;
};

/** The cards that are read together, under the name the rail and the column both give them. */
export type ProjectSettingsNavGroup = {
    label: string;
    items: ProjectSettingsNavItem[];
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
