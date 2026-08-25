import type { PageNode } from '@/modules/page/types';

export type MoveDirection = 'up' | 'down' | 'in' | 'out';

/** What `pages.placement.update` takes: a parent to sit under, and a sibling to sit behind. */
export type Placement = { parent: string | null; after: string | null };

type Position = { siblings: PageNode[]; index: number; parent: PageNode | null; grandparent: PageNode | null };

/**
 * Where a page sits in a tree: the list it belongs to, its place in that list, and the two levels
 * above it. Everything a move needs to be expressed as "under this, behind that".
 */
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

/**
 * The placement a direction means, or null when it cannot apply.
 *
 * Expressed as a parent and an anchor rather than as a position, because that is what the server
 * accepts (ADR-0009): a stale tree cannot compute a slot from what it last saw.
 */
export function placementFor(tree: PageNode[], pageId: string, direction: MoveDirection): Placement | null {
    const at = locate(tree, pageId);

    if (at === null) {
        return null;
    }

    const { siblings, index, parent, grandparent } = at;
    const parentId = parent?.id ?? null;

    switch (direction) {
        case 'up':
            // Behind whatever the page above it is behind — which at the top of the list is
            // nothing, and nothing is the front.
            return index === 0 ? null : { parent: parentId, after: index >= 2 ? siblings[index - 2].id : null };

        case 'down':
            return index >= siblings.length - 1 ? null : { parent: parentId, after: siblings[index + 1].id };

        case 'in': {
            // Inside the page above it, at the end of what is already written there.
            const target = index === 0 ? null : siblings[index - 1];

            if (target === null) {
                return null;
            }

            const last = target.children[target.children.length - 1];

            return { parent: target.id, after: last?.id ?? null };
        }

        case 'out':
            // Out beside its own parent, immediately after it, so the page does not jump to the
            // top of a list it has never been in.
            return parent === null ? null : { parent: grandparent?.id ?? null, after: parent.id };
    }
}
