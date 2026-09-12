import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { Ref } from 'vue';
import PlacementController from '@/actions/App/Http/Controllers/Placement/PlacementController';
import { perFrame } from '@/lib/perFrame';
import type { BoardCardData, BoardColumnData } from '@/modules/task/types';

type Movable = { placementId?: string };
type Grouped<T extends Movable> = { id: string | null; tasks: T[] };

export type DragSurface = {
    cardSelector: string;
    /** Prop reloaded when the server refuses a move. */
    reloadKey: string;
};

const BOARD: DragSurface = { cardSelector: '[data-task-card]', reloadKey: 'board' };

export type BoardDrag = {
    draggingId: Ref<string | null>;
    overColumn: Ref<string | null>;
    /** `before` is the placement the card would land above; `null` is the end of the group. */
    dropTarget: Ref<{ key: string; before: string | null } | null>;
    pickUp: (event: PointerEvent, card: BoardCardData) => void;
    /** `rollbackTo` is the board as it was when the card was picked up, not after the last step. */
    commit: (placementId: string, columnKey: string, beforeId: string | null, rollbackTo: BoardColumnData[]) => void;
    snapshot: () => BoardColumnData[];
    moveTo: (placementId: string, columnKey: string) => void;
};

const keyOf = (columnId: string | null): string => columnId ?? 'ungrouped';

/** Pixels of pointer travel below which a press is a click, not a drag. */
const THRESHOLD = 4;

// Pointer events rather than HTML5 drag and drop, which cannot be driven synthetically and has no
// touch support. A drop sends its neighbour, never an index. See ADR-0009.
export function useBoardDragAndDrop(columns: Ref<BoardColumnData[]>, enabled: () => boolean): BoardDrag {
    return useTaskDragAndDrop(columns, enabled, BOARD) as BoardDrag;
}

export function useTaskDragAndDrop<T extends Movable, C extends Grouped<T>>(
    columns: Ref<C[]>,
    enabled: () => boolean,
    surface: DragSurface,
) {
    const draggingId = ref<string | null>(null);
    const overColumn = ref<string | null>(null);
    const dropTarget = ref<{ key: string; before: string | null } | null>(null);

    const find = (placementId: string): { column: C; index: number } | null => {
        for (const column of columns.value) {
            const index = column.tasks.findIndex((card) => card.placementId === placementId);

            if (index !== -1) {
                return { column, index };
            }
        }

        return null;
    };

    const snapshot = (): C[] => columns.value.map((column) => ({ ...column, tasks: [...column.tasks] }));

    const targetUnder = (x: number, y: number): { key: string; before: string | null } | null => {
        const element = document.elementFromPoint(x, y);
        const column = element?.closest<HTMLElement>('[data-column-key]');

        if (!column) {
            return null;
        }

        const cards = Array.from(column.querySelectorAll<HTMLElement>(surface.cardSelector));

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
        rollbackTo: C[],
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
                    // The snapshot is only right about this card, so the board is reloaded as well.
                    columns.value = rollbackTo;

                    router.reload({ only: [surface.reloadKey] });
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

        if (origin.column === target && origin.index === index) {
            return;
        }

        const after = index === 0 ? null : target.tasks[index - 1];

        send(placementId, target.id, after?.placementId ?? null, previous);
    };

    return {
        draggingId,
        overColumn,
        dropTarget,
        snapshot,
        moveTo: (placementId: string, columnKey: string): void => move(placementId, columnKey, null),

        commit(placementId: string, columnKey: string, beforeId: string | null, rollbackTo: C[]): void {
            const target = columns.value.find((column) => keyOf(column.id) === columnKey);

            if (target === undefined) {
                return;
            }

            send(placementId, target.id, beforeId, rollbackTo);
        },

        pickUp(event: PointerEvent, card: T): void {
            if (!enabled() || event.button !== 0) {
                return;
            }

            const startX = event.clientX;
            const startY = event.clientY;
            let dragging = false;

            const track = perFrame((x: number, y: number): void => {
                const under = targetUnder(x, y);

                overColumn.value = under?.key ?? null;
                dropTarget.value = under;
            });

            const onMove = (moved: PointerEvent): void => {
                if (!dragging && Math.hypot(moved.clientX - startX, moved.clientY - startY) < THRESHOLD) {
                    return;
                }

                dragging = true;
                draggingId.value = card.placementId ?? null;

                track.call(moved.clientX, moved.clientY);
            };

            const onUp = (up: PointerEvent): void => {
                document.removeEventListener('pointermove', onMove);
                document.removeEventListener('pointerup', onUp);
                track.cancel();

                const wasDragging = dragging;

                dragging = false;
                draggingId.value = null;
                overColumn.value = null;
                dropTarget.value = null;

                if (!wasDragging) {
                    return;
                }

                const target = targetUnder(up.clientX, up.clientY);

                if (target !== null && card.placementId !== undefined) {
                    move(card.placementId, target.key, target.before);
                }
            };

            document.addEventListener('pointermove', onMove, { passive: true });
            document.addEventListener('pointerup', onUp, { passive: true });
        },
    };
}
