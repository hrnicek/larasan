import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { Ref } from 'vue';
import PlacementController from '@/actions/App/Http/Controllers/Placement/PlacementController';
import type { BoardCardData, BoardColumnData } from '@/modules/task/types';

export type BoardDrag = {
    draggingId: Ref<string | null>;
    overColumn: Ref<string | null>;
    pickUp: (event: PointerEvent, card: BoardCardData) => void;
    /**
     * The request a drop makes, shared with the keyboard path so the two cannot disagree
     * about what a move means. `rollbackTo` is the board as it was before the move began —
     * the keyboard path moves a card several times before dropping it, and "before" is where
     * it was picked up, not where the last arrow left it.
     */
    commit: (placementId: string, columnKey: string, beforeId: string | null, rollbackTo: BoardColumnData[]) => void;
    /** The board as it stands, for a caller that is about to change it. */
    snapshot: () => BoardColumnData[];
};

const keyOf = (columnId: string | null): string => columnId ?? 'ungrouped';

/** Below this the pointer was a click, not a drag. */
const THRESHOLD = 4;

/**
 * Picking a card up and putting it down.
 *
 * Pointer events rather than HTML5 drag and drop. The native API cannot be driven by a
 * synthetic pointer, which would make TASK-090-016's requirement — that an actual drag has
 * been observed working — impossible to meet honestly; it also has no touch support, so the
 * board would have needed a second implementation for phones anyway.
 *
 * What a drop produces is "this card, into this column, after that one" — the shape
 * `MoveTaskInProject` already takes (ADR-0009). Never an index: an index is a number the
 * server would have to trust from a board that may be seconds out of date, and two people
 * dragging at once is exactly when it would be wrong.
 *
 * This is the one place optimistic UI is permitted, and the rollback is what earns it: the
 * card moves locally, the request confirms it, and a failure puts the card back in the slot it
 * came from rather than merely the column. The message is the server's — the refusal renderer
 * already flashes it.
 */
export function useBoardDragAndDrop(columns: Ref<BoardColumnData[]>, enabled: () => boolean): BoardDrag {
    const draggingId = ref<string | null>(null);
    const overColumn = ref<string | null>(null);

    const find = (placementId: string): { column: BoardColumnData; index: number } | null => {
        for (const column of columns.value) {
            const index = column.tasks.findIndex((card) => card.placementId === placementId);

            if (index !== -1) {
                return { column, index };
            }
        }

        return null;
    };

    const snapshot = (): BoardColumnData[] =>
        columns.value.map((column) => ({ ...column, tasks: [...column.tasks] }));

    /** The column under the pointer, and which card the dragged one would land above. */
    const targetUnder = (x: number, y: number): { key: string; before: string | null } | null => {
        const element = document.elementFromPoint(x, y);
        const column = element?.closest<HTMLElement>('[data-column-key]');

        if (!column) {
            return null;
        }

        const cards = Array.from(column.querySelectorAll<HTMLElement>('[data-task-card]'));

        const before = cards.find((card) => {
            const box = card.getBoundingClientRect();

            return y < box.top + box.height / 2;
        });

        return {
            key: column.dataset.columnKey ?? 'ungrouped',
            before: before?.dataset.placementId ?? null,
        };
    };

    const send = (
        placementId: string,
        section: string | null,
        beforeId: string | null,
        rollbackTo: BoardColumnData[],
    ): void => {
        router.put(
            PlacementController.move.url(placementId),
            {
                section,
                ...(beforeId === null ? { at: 'front' } : { after: beforeId }),
            },
            {
                preserveScroll: true,
                onError: () => {
                    // Back to the exact slot, not merely the column — and then ask the server
                    // what the board actually looks like. A refusal usually means somebody
                    // else moved something, and the snapshot is only right about this card.
                    columns.value = rollbackTo;

                    router.reload({ only: ['board'] });
                },
            },
        );
    };

    const move = (placementId: string, targetKey: string, beforeId: string | null): void => {
        const origin = find(placementId);
        const target = columns.value.find((column) => keyOf(column.id) === targetKey);

        if (origin === null || target === undefined) {
            return;
        }

        const card = origin.column.tasks[origin.index];
        const previous = snapshot();

        origin.column.tasks.splice(origin.index, 1);

        const at = beforeId === null
            ? target.tasks.length
            : target.tasks.findIndex((other) => other.placementId === beforeId);
        const index = at === -1 ? target.tasks.length : at;

        target.tasks.splice(index, 0, card);

        // Dropped where it already was: nothing to write, and nothing to announce.
        if (origin.column === target && origin.index === index) {
            return;
        }

        // The neighbour it now follows. No neighbour means the top of the column, which the
        // server hears as `at: 'front'` — never as a position.
        const after = index === 0 ? null : target.tasks[index - 1];

        send(placementId, target.id, after?.placementId ?? null, previous);
    };

    return {
        draggingId,
        overColumn,
        snapshot,

        commit(placementId: string, columnKey: string, beforeId: string | null, rollbackTo: BoardColumnData[]): void {
            const target = columns.value.find((column) => keyOf(column.id) === columnKey);

            if (target === undefined) {
                return;
            }

            send(placementId, target.id, beforeId, rollbackTo);
        },

        pickUp(event: PointerEvent, card: BoardCardData): void {
            if (!enabled() || event.button !== 0) {
                return;
            }

            const startX = event.clientX;
            const startY = event.clientY;
            let dragging = false;

            const onMove = (moved: PointerEvent): void => {
                if (!dragging && Math.hypot(moved.clientX - startX, moved.clientY - startY) < THRESHOLD) {
                    return;
                }

                dragging = true;
                draggingId.value = card.placementId;
                overColumn.value = targetUnder(moved.clientX, moved.clientY)?.key ?? null;
            };

            const onUp = (up: PointerEvent): void => {
                document.removeEventListener('pointermove', onMove);
                document.removeEventListener('pointerup', onUp);

                const wasDragging = dragging;

                dragging = false;
                draggingId.value = null;
                overColumn.value = null;

                if (!wasDragging) {
                    return;
                }

                const target = targetUnder(up.clientX, up.clientY);

                if (target !== null) {
                    move(card.placementId, target.key, target.before);
                }
            };

            document.addEventListener('pointermove', onMove);
            document.addEventListener('pointerup', onUp);
        },
    };
}
