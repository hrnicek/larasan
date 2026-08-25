/**
 * A document, as ProseMirror's JSON and nothing else. Written recursively rather than as
 * `Record<string, unknown>` because the editor's value travels through Inertia's form data,
 * which types what it can serialise — and `unknown` is not that.
 */
export type DocumentValue =
    | string
    | number
    | boolean
    | null
    | DocumentValue[]
    | { [key: string]: DocumentValue };

export type PageDocument = { [key: string]: DocumentValue };

/** A page's own screen: the document, and the number a save has to carry. */
export type PageDetail = {
    id: string;
    title: string;
    content: PageDocument;
    version: number;
    updatedAt: string | null;
    /** The name of whoever last changed it, or null for a page whose author has left. */
    updatedBy: string | null;
};

/**
 * A node in a project's page tree.
 *
 * The document itself is deliberately absent: a tree draws titles and first lines, and `content`
 * is the one column on a page that can be a hundred kilobytes.
 */
export type PageNode = {
    id: string;
    parentId: string | null;
    title: string;
    excerpt: string | null;
    updatedAt: string | null;
    children: PageNode[];
};

/**
 * The pages view's payload. The three abilities are the project policy's answers, computed once
 * on the server for the whole tree — every page in a project answers the same way — and rendered
 * by the client, which derives none of them.
 */
export type ProjectPages = {
    tree: PageNode[];
    can: {
        createPage: boolean;
        updatePage: boolean;
        deletePage: boolean;
    };
};
