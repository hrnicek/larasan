import type { Component } from 'vue';
import type { CustomFieldType } from '@/modules/custom-field/types';
import type { ListColumn } from '@/modules/task/listColumns';

export type ProjectSummary = {
    id: string;
    name: string;
    slug: string;
    color: string | null;
    icon: string | null;
    visibility: string;
};

export type SidebarProject = {
    id: string;
    name: string;
    slug: string;
    color: string | null;
    icon: string | null;
    canUpdate: boolean;
    canArchive: boolean;
    /** The current user's star, not a project property. */
    starred: boolean;
};

export type ProjectHeading = {
    id: string;
    name: string;
    slug: string;
    color: string | null;
    icon: string | null;
    archived: boolean;
    canUpdate: boolean;
    starred: boolean;
    canCustomize: boolean;
    /** At most five; `memberCount` is the total. */
    members: ProjectPerson[];
    memberCount: number;
};

export type ProjectPerson = {
    id: number;
    name: string;
    avatar: string | null;
};

export type ProjectMember = ProjectPerson & {
    membershipId: string;
    /** Absent for a guest reader. */
    email?: string;
    accessLevel: string;
    isYou: boolean;
    isLastOwner: boolean;
};

export type ProjectShare = {
    canManage: boolean;
    visibility: string;
    accessLevels: string[];
    link: string;
    members: ProjectMember[];
    candidates: (ProjectPerson & { email?: string })[];
};

export type ProjectCustomize = {
    fields: ProjectCustomFields;
    columns: ListColumn[];
};

export type ProjectSettings = ProjectSummary & {
    description: string | null;
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

/** `id` is the settings card's element id, used for scrolling and scroll-spy. */
export type ProjectSettingsNavItem = {
    id: string;
    label: string;
    icon: Component;
};

export type ProjectSettingsNavGroup = {
    label: string;
    items: ProjectSettingsNavItem[];
};

export type ProjectAbilities = {
    update: boolean;
    archive: boolean;
    manageFields: boolean;
};

export type ProjectCustomFields = {
    attached: { id: string; name: string; type: CustomFieldType }[];
    available: { id: string; name: string; type: CustomFieldType }[];
};

/** `kind` is derived from the detected MIME type, not the file extension. */
export type ProjectFile = {
    id: string;
    name: string;
    size: number;
    extension: string;
    kind: string;
    uploader: {
        id: number;
        name: string;
        email?: string;
        avatar: string | null;
    } | null;
    task: { id: string; title: string } | null;
    attachedAt: string | null;
    canDelete: boolean;
};

export type ProjectFiles = {
    files: ProjectFile[];
    meta: {
        page: number;
        perPage: number;
        total: number;
        hasMore: boolean;
        sort: string;
        direction: string;
    };
};
