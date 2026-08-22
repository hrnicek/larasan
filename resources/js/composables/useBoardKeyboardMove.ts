import { nextTick, ref } from 'vue';
import type { Ref } from 'vue';
import type { BoardCardData, BoardColumnData } from '@/modules/task/types';

export type BoardKeyboardMove = {
    /** The card currently picked up, or null. */
    carrying: Ref<string | null>;
    announcement: Ref<string>;
    onKeydown: (event: KeyboardEvent) => void;
};

const keyOf = (column: BoardColumnData): string => column.id ?? 'ungrouped';
const nameOf = (column: BoardColumnData): string => column.name ?? 'No section';

/**
 * Moving a card without a pointer — the required path, not the fallback.
 *
 * `Space` picks a card up, the arrows move it, `Space` drops it and `Esc` puts it back. Every
 * change is announced through a live region, because a move nobody can see is a move nobody
 * can follow: the card's new column and place are the whole feedback a keyboard user gets.
 *
 * It ends in the same request a drag does — "this card, into this column, after that one" —
 * so the two paths cannot disagree about what a move means.
 */
export function useBoardKeyboardMove(
    columns: Ref<BoardColumnData[]>,
    enabled: () => boolean,
    move: {
        commit: (placementId: string, columnKey: string, beforeId: string | null, rollbackTo: BoardColumnData[]) => void;
        snapshot: () => BoardColumnData[];
    },
): BoardKeyboardMove {
    const carrying = ref<string | null>(null);
    const announcement = ref('');

    // Where the card was when it was picked up: what `Esc` restores, and what a refusal
    // rolls back to.
    let origin: BoardColumnData[] = [];

    const locate = (placementId: string): { column: BoardColumnData; index: number } | null => {
        for (const column of columns.value) {
            const index = column.tasks.findIndex((card: BoardCardData) => card.placementId === placementId);

            if (index !== -1) {
                return { column, index };
            }
        }

        return null;
    };

    const announce = (column: BoardColumnData, index: number): void => {
        announcement.value = `${nameOf(column)}, position ${index + 1} of ${column.tasks.length}`;
    };

    /**
     * Vue rebuilds the card's element when it changes column, so the focus it had goes with
     * the old one — and a carried card nobody can send keys to is a card stuck mid-move.
     */
    const keepFocus = (placementId: string): void => {
        void nextTick(() => {
            document.querySelector<HTMLElement>(`[data-placement-id="${placementId}"]`)?.focus();
        });
    };

    /**
     * The card this one now follows — the anchor the server is told about. `null` means the
     * top of the column, which the request sends as `at: 'front'`; it is never a position.
     */
    const follows = (column: BoardColumnData, index: number, placementId: string): string | null => {
        if (index === 0) {
            return null;
        }

        const others = column.tasks.filter((card) => card.placementId !== placementId);

        return others[index - 1]?.placementId ?? null;
    };

    return {
        carrying,
        announcement,

        onKeydown(event: KeyboardEvent): void {
            const card = (event.target as HTMLElement).closest<HTMLElement>('[data-task-card]');
            const placementId = carrying.value ?? card?.dataset.placementId ?? null;

            if (placementId === null || !enabled()) {
                return;
            }

            const found = locate(placementId);

            if (found === null) {
                return;
            }

            const { column, index } = found;
            const columnIndex = columns.value.indexOf(column);

            if (event.key === ' ' || event.key === 'Spacebar') {
                event.preventDefault();

                if (carrying.value === null) {
                    carrying.value = placementId;
                    origin = move.snapshot();
                    announcement.value = `Picked up. ${nameOf(column)}, position ${index + 1} of ${column.tasks.length}.`;

                    return;
                }

                carrying.value = null;
                announcement.value = `Dropped in ${nameOf(column)}, position ${index + 1}.`;

                // The card is already where the arrows put it, so this only sends — and it
                // sends the neighbour above it, the same "place after that one" a drag does.
                move.commit(placementId, keyOf(column), follows(column, index, placementId), origin);

                return;
            }

            if (carrying.value === null) {
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                carrying.value = null;
                columns.value = origin;
                announcement.value = 'Cancelled.';

                return;
            }

            const vertical = event.key === 'ArrowUp' ? -1 : event.key === 'ArrowDown' ? 1 : 0;
            const horizontal = event.key === 'ArrowLeft' ? -1 : event.key === 'ArrowRight' ? 1 : 0;

            if (vertical === 0 && horizontal === 0) {
                return;
            }

            event.preventDefault();

            if (vertical !== 0) {
                const next = Math.min(Math.max(index + vertical, 0), column.tasks.length - 1);

                if (next === index) {
                    return;
                }

                const [moved] = column.tasks.splice(index, 1);
                column.tasks.splice(next, 0, moved);
                announce(column, next);
                keepFocus(placementId);

                return;
            }

            const target = columns.value[columnIndex + horizontal];

            if (target === undefined) {
                return;
            }

            const [moved] = column.tasks.splice(index, 1);
            const landing = Math.min(index, target.tasks.length);

            target.tasks.splice(landing, 0, moved);
            announce(target, landing);
            keepFocus(placementId);
        },
    };
}
