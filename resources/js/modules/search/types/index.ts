/**
 * What the palette's endpoint answers with (`search.suggestions`).
 *
 * One named type per kind rather than an index signature: a prop the server stops sending should
 * break `types:check`, which is the whole reason these exist.
 */
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

export type SearchAnswer = {
    results: SearchResults;
    meta: {
        term: string;
        kind: SearchKind | null;
        /** True when the engine could not be reached and tasks came from PostgreSQL instead. */
        degraded: boolean;
    };
};
