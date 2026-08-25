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
