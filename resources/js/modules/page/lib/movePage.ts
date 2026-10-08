import type { PageNode } from '@/modules/page/types';

export type MoveDirection = 'up' | 'down' | 'in' | 'out';

export type Placement = { parent: string | null; after: string | null };

type Position = {
    siblings: PageNode[];
    index: number;
    parent: PageNode | null;
    grandparent: PageNode | null;
};

function locate(
    tree: PageNode[],
    id: string,
    parent: PageNode | null = null,
    grandparent: PageNode | null = null,
): Position | null {
    const index = tree.findIndex((node) => node.id === id);

    if (index !== -1) {
        return { siblings: tree, index, parent, grandparent };
    }

    for (const node of tree) {
        const found = locate(node.children, id, node, parent);

        if (found !== null) {
            return found;
        }
    }

    return null;
}

// A parent and an anchor rather than a position. See ADR-0009.
export function placementFor(
    tree: PageNode[],
    pageId: string,
    direction: MoveDirection,
): Placement | null {
    const at = locate(tree, pageId);

    if (at === null) {
        return null;
    }

    const { siblings, index, parent, grandparent } = at;
    const parentId = parent?.id ?? null;

    switch (direction) {
        case 'up':
            // Behind the sibling two above, or at the front.
            return index === 0
                ? null
                : {
                      parent: parentId,
                      after: index >= 2 ? siblings[index - 2].id : null,
                  };

        case 'down':
            return index >= siblings.length - 1
                ? null
                : { parent: parentId, after: siblings[index + 1].id };

        case 'in': {
            const target = index === 0 ? null : siblings[index - 1];

            if (target === null) {
                return null;
            }

            const last = target.children[target.children.length - 1];

            return { parent: target.id, after: last?.id ?? null };
        }

        case 'out':
            return parent === null
                ? null
                : { parent: grandparent?.id ?? null, after: parent.id };
    }
}
