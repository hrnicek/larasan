export type SearchKind = 'tasks' | 'projects' | 'people' | 'messages';

export type SearchPerson = {
    id: number;
    name: string;
    email: string;
};

export type SearchTaskProject = {
    id: string;
    name: string;
    color: string | null;
    icon: string | null;
};

export type SearchTaskResult = {
    id: string;
    title: string;
    dueAt: string | null;
    completedAt: string | null;
    priority: string;
    assignee: SearchPerson | null;
    projects: SearchTaskProject[];
};

export type SearchProjectResult = {
    id: string;
    name: string;
    slug: string;
    color: string | null;
    icon: string | null;
    archived: boolean;
};

export type SearchPersonResult = SearchPerson & {
    avatar: string | null;
    role: string | null;
};

export type SearchMessageResult = {
    id: string;
    excerpt: string;
    createdAt: string | null;
    edited: boolean;
    author: SearchPerson | null;
    task: { id: string; title: string } | null;
};

export type SearchResults = {
    tasks?: SearchTaskResult[];
    projects?: SearchProjectResult[];
    people?: SearchPersonResult[];
    messages?: SearchMessageResult[];
};

export type SavedSearch = {
    id: string;
    name: string;
    term: string;
    kind: SearchKind | null;
    filters: {
        project?: string;
        assignee?: number;
        completed?: boolean;
    };
};

export type RecentItem =
    | { kind: 'tasks'; id: string; title: string; completed: boolean }
    | { kind: 'projects'; id: string; title: string; color: string | null; icon: string | null; archived: boolean };

export type SearchAnswer = {
    results: SearchResults;
    /** Only sent for an empty term. */
    saved: SavedSearch[];
    /** Only sent for an empty term. */
    recents: RecentItem[];
    meta: {
        term: string;
        kind: SearchKind | null;
        /** The engine was unreachable and tasks came from PostgreSQL instead. */
        degraded: boolean;
    };
};
