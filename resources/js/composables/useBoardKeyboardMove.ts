import { nextTick, onScopeDispose, ref } from 'vue';
import type { Ref } from 'vue';
import type { BoardCardData, BoardColumnData } from '@/modules/task/types';

export type BoardKeyboardMove = {
    carrying: Ref<string | null>;
    announcement: Ref<string>;
    onKeydown: (event: KeyboardEvent) => void;
};

const keyOf = (column: BoardColumnData): string => column.id ?? 'ungrouped';
const nameOf = (column: BoardColumnData): string => column.name ?? 'No section';
const cardOf = (placementId: string): string => `[data-task-card][data-placement-id="${placementId}"]`;

export function useBoardKeyboardMove(
    columns: Ref<BoardColumnData[]>,
    enabled: () => boolean,
    move: {
        commit: (placementId: string, columnKey: string, afterId: string | null, rollbackTo: BoardColumnData[]) => void;
        snapshot: () => BoardColumnData[];
    },
): BoardKeyboardMove {
    const carrying = ref<string | null>(null);
    const announcement = ref('');

    let origin: BoardColumnData[] = [];
    let focusCheck: number | undefined;

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

    // Vue recreates the card's element when it changes column, which drops its focus.
    const keepFocus = (placementId: string): void => {
        void nextTick(() => {
            document.querySelector<HTMLElement>(cardOf(placementId))?.focus();
        });
    };

    const follows = (column: BoardColumnData, index: number, placementId: string): string | null => {
        if (index === 0) {
            return null;
        }

        const others = column.tasks.filter((card) => card.placementId !== placementId);

        return others[index - 1]?.placementId ?? null;
    };

    const release = (): void => {
        carrying.value = null;
        window.clearTimeout(focusCheck);
        document.removeEventListener('focusout', onFocusOut);
    };

    const cancel = (): void => {
        release();
        columns.value = origin;
        announcement.value = 'Cancelled.';
    };

    // Moving the card detaches its element for a moment, so focus is judged after keepFocus has run.
    const onFocusOut = (): void => {
        window.clearTimeout(focusCheck);

        focusCheck = window.setTimeout(() => {
            if (carrying.value !== null && document.activeElement?.matches(cardOf(carrying.value)) !== true) {
                cancel();
            }
        });
    };

    const hold = (placementId: string): void => {
        carrying.value = placementId;
        origin = move.snapshot();
        document.addEventListener('focusout', onFocusOut);
    };

    onScopeDispose(release, true);

    return {
        carrying,
        announcement,

        onKeydown(event: KeyboardEvent): void {
            const placementId = event.target instanceof HTMLElement && event.target.matches('[data-task-card]')
                ? event.target.dataset.placementId ?? null
                : null;

            if (placementId === null || !enabled() || (carrying.value !== null && carrying.value !== placementId)) {
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
                    hold(placementId);
                    announcement.value = `Picked up. ${nameOf(column)}, position ${index + 1} of ${column.tasks.length}.`;

                    return;
                }

                release();
                announcement.value = `Dropped in ${nameOf(column)}, position ${index + 1}.`;

                move.commit(placementId, keyOf(column), follows(column, index, placementId), origin);

                return;
            }

            if (carrying.value === null) {
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                cancel();
                keepFocus(placementId);

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
