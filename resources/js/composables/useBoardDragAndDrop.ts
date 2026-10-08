import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { Ref } from 'vue';
import PlacementController from '@/actions/App/Http/Controllers/Placement/PlacementController';
import { usePointerDrag } from '@/composables/usePointerDrag';
import type { BoardCardData, BoardColumnData } from '@/modules/task/types';

type Movable = { placementId?: string };
type Grouped<T extends Movable> = { id: string | null; tasks: T[] };

/** `before` is the placement the card would land above; `null` is the end of the group. */
type DropTarget = { key: string; before: string | null };

export type DragSurface = {
    cardSelector: string;
    /** Prop reloaded when the server refuses a move. */
    reloadKey: string;
};

const BOARD: DragSurface = {
    cardSelector: '[data-task-card]',
    reloadKey: 'board',
};

export type BoardDrag = {
    draggingId: Ref<string | null>;
    overColumn: Ref<string | null>;
    dropTarget: Ref<DropTarget | null>;
    pickUp: (event: PointerEvent, card: BoardCardData) => void;
    /** `rollbackTo` is the board as it was when the card was picked up, not after the last step. */
    commit: (
        placementId: string,
        columnKey: string,
        afterId: string | null,
        rollbackTo: BoardColumnData[],
    ) => void;
    snapshot: () => BoardColumnData[];
    moveTo: (placementId: string, columnKey: string) => void;
};

const keyOf = (columnId: string | null): string => columnId ?? 'ungrouped';

// Pointer events rather than HTML5 drag and drop, which cannot be driven synthetically and has no
// touch support. A drop sends its neighbour, never an index. See ADR-0009.
export function useBoardDragAndDrop(
    columns: Ref<BoardColumnData[]>,
    enabled: () => boolean,
): BoardDrag {
    return useTaskDragAndDrop(columns, enabled, BOARD) as BoardDrag;
}

export function useTaskDragAndDrop<T extends Movable, C extends Grouped<T>>(
    columns: Ref<C[]>,
    enabled: () => boolean,
    surface: DragSurface,
) {
    const draggingId = ref<string | null>(null);
    const overColumn = ref<string | null>(null);
    const dropTarget = ref<DropTarget | null>(null);
    const beginDrag = usePointerDrag();

    const find = (placementId: string): { column: C; index: number } | null => {
        for (const column of columns.value) {
            const index = column.tasks.findIndex(
                (card) => card.placementId === placementId,
            );

            if (index !== -1) {
                return { column, index };
            }
        }

        return null;
    };

    const snapshot = (): C[] =>
        columns.value.map((column) => ({
            ...column,
            tasks: [...column.tasks],
        }));

    const targetUnder = (
        x: number,
        y: number,
        carried: string,
    ): DropTarget | null => {
        const column = document
            .elementFromPoint(x, y)
            ?.closest<HTMLElement>('[data-column-key]');

        if (!column) {
            return null;
        }

        // The carried card is skipped, or releasing over its own slot would name it as its own neighbour.
        const before = Array.from(
            column.querySelectorAll<HTMLElement>(surface.cardSelector),
        )
            .filter((card) => card.dataset.placementId !== carried)
            .find((card) => {
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
        afterId: string | null,
        rollbackTo: C[],
    ): void => {
        router.put(
            PlacementController.move.url(placementId),
            {
                section,
                ...(afterId === null ? { at: 'front' } : { after: afterId }),
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

    const move = (
        placementId: string,
        targetKey: string,
        beforeId: string | null,
    ): void => {
        const origin = find(placementId);
        const target = columns.value.find(
            (column) => keyOf(column.id) === targetKey,
        );

        if (
            origin === null ||
            target === undefined ||
            beforeId === placementId
        ) {
            return;
        }

        const others = target.tasks.filter(
            (card) => card.placementId !== placementId,
        );
        const index =
            beforeId === null
                ? others.length
                : others.findIndex((card) => card.placementId === beforeId);

        if (
            index === -1 ||
            (origin.column === target && origin.index === index)
        ) {
            return;
        }

        const previous = snapshot();
        const [card] = origin.column.tasks.splice(origin.index, 1);

        target.tasks.splice(index, 0, card);

        send(
            placementId,
            target.id,
            others[index - 1]?.placementId ?? null,
            previous,
        );
    };

    const reset = (): void => {
        draggingId.value = null;
        overColumn.value = null;
        dropTarget.value = null;
    };

    return {
        draggingId,
        overColumn,
        dropTarget,
        snapshot,
        moveTo: (placementId: string, columnKey: string): void =>
            move(placementId, columnKey, null),

        commit(
            placementId: string,
            columnKey: string,
            afterId: string | null,
            rollbackTo: C[],
        ): void {
            const target = columns.value.find(
                (column) => keyOf(column.id) === columnKey,
            );

            if (target === undefined) {
                return;
            }

            const was = rollbackTo.find((column) =>
                column.tasks.some((card) => card.placementId === placementId),
            );

            if (was !== undefined && keyOf(was.id) === columnKey) {
                const index = was.tasks.findIndex(
                    (card) => card.placementId === placementId,
                );

                if ((was.tasks[index - 1]?.placementId ?? null) === afterId) {
                    return;
                }
            }

            send(placementId, target.id, afterId, rollbackTo);
        },

        pickUp(event: PointerEvent, card: T): void {
            const placementId = card.placementId;

            if (!enabled() || placementId === undefined) {
                return;
            }

            beginDrag(event, {
                start: () => {
                    draggingId.value = placementId;
                },
                move: (x, y) => {
                    const under = targetUnder(x, y, placementId);

                    overColumn.value = under?.key ?? null;
                    dropTarget.value = under;
                },
                drop: (x, y) => {
                    reset();

                    const target = targetUnder(x, y, placementId);

                    if (target !== null) {
                        move(placementId, target.key, target.before);
                    }
                },
                cancel: reset,
            });
        },
    };
}
