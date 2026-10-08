// Recursive rather than `Record<string, unknown>`, which Inertia's form data typing does not accept.
export type DocumentValue =
    | string
    | number
    | boolean
    | null
    | DocumentValue[]
    | { [key: string]: DocumentValue };

export type PageDocument = { [key: string]: DocumentValue };

export type PageDetail = {
    id: string;
    title: string;
    content: PageDocument;
    version: number;
    updatedAt: string | null;
    /** Null when the author has left. */
    updatedBy: string | null;
};

export type PageNode = {
    id: string;
    parentId: string | null;
    title: string;
    excerpt: string | null;
    updatedAt: string | null;
    children: PageNode[];
};

export type ProjectPages = {
    tree: PageNode[];
    can: {
        createPage: boolean;
        updatePage: boolean;
        deletePage: boolean;
    };
};
