import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { Ref } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import type { CalendarCardData, CalendarDay, ProjectCalendar } from '@/modules/task/types';

/** Below this the pointer was a click on a chip, not a drag of one. */
const THRESHOLD = 4;

export type CalendarSnapshot = { days: CalendarDay[]; undated: ProjectCalendar['undated'] };

/**
 * Dropping a task on a day.
 *
 * Pointer events rather than HTML5 drag and drop, for the reasons the board settled on them: the
 * native API cannot be driven by a synthetic pointer, so a drag could never be *observed*
 * working, and it has no touch support.
 *
 * What a drop produces is a due date and nothing else. Not a position, not a column — a card on
 * the 7th is a task due on the 7th, so the request is the same `tasks.update` the date picker
 * sends. Which means a drag is refused by the same permission that refuses the picker, and the
 * calendar needs no endpoint of its own.
 *
 * This is the second place optimistic UI is permitted (ADR-0009 allows it for card movement),
 * and the rollback is what earns it: the chip moves locally, the request confirms it, and a
 * refusal puts it back on the day it came from and then asks the server what the month really
 * looks like.
 */
export function useCalendarDrag(
    days: Ref<CalendarDay[]>,
    undated: Ref<ProjectCalendar['undated']>,
    enabled: () => boolean,
) {
    const draggingId = ref<string | null>(null);
    const overDay = ref<string | null>(null);

    const snapshot = (): CalendarSnapshot => ({
        days: days.value.map((day) => ({ ...day, tasks: [...day.tasks] })),
        undated: { ...undated.value, tasks: [...undated.value.tasks] },
    });

    /** The day under the pointer, when there is one. The tray is a source, never a target. */
    const dayUnder = (x: number, y: number): string | null => {
        const cell = document.elementFromPoint(x, y)?.closest<HTMLElement>('[data-calendar-day]');

        return cell?.dataset.calendarDay ?? null;
    };

    /** Where this card is now: a day of the grid, or the tray of unscheduled work. */
    const locate = (placementId: string): CalendarDay | ProjectCalendar['undated'] | null =>
        days.value.find((day) => day.tasks.some((card) => card.placementId === placementId))
        ?? (undated.value.tasks.some((card) => card.placementId === placementId) ? undated.value : null);

    const send = (taskId: string, date: string, rollbackTo: CalendarSnapshot): void => {
        router.put(
            TaskController.update.url(taskId),
            { due_at: date },
            {
                preserveScroll: true,
                preserveState: true,
                onError: () => {
                    days.value = rollbackTo.days;
                    undated.value = rollbackTo.undated;

                    router.reload({ only: ['calendar'] });
                },
            },
        );
    };

    const move = (card: CalendarCardData, date: string): void => {
        const origin = locate(card.placementId);
        const target = days.value.find((day) => day.date === date);

        if (origin === null || target === undefined) {
            return;
        }

        // Dropped where it already was: nothing to write, and nothing to move.
        if ('date' in origin && origin.date === date) {
            return;
        }

        const previous = snapshot();

        origin.tasks = origin.tasks.filter((held) => held.placementId !== card.placementId);
        origin.count -= 1;

        /*
         * The chip carries its new date locally so the cell it lands in reads correctly before
         * the server answers. A cell that is already showing a full page grows its count instead
         * — the card is real, it is simply on the next page of that day.
         */
        target.tasks = [...target.tasks, { ...card, dueAt: `${date}T00:00:00+00:00` }];
        target.count += 1;

        send(card.id, date, previous);
    };

    return {
        draggingId,
        overDay,

        pickUp(event: PointerEvent, card: CalendarCardData): void {
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
                overDay.value = dayUnder(moved.clientX, moved.clientY);
            };

            const onUp = (up: PointerEvent): void => {
                document.removeEventListener('pointermove', onMove);
                document.removeEventListener('pointerup', onUp);

                const wasDragging = dragging;

                dragging = false;
                draggingId.value = null;
                overDay.value = null;

                if (!wasDragging) {
                    return;
                }

                const date = dayUnder(up.clientX, up.clientY);

                if (date !== null) {
                    move(card, date);
                }
            };

            document.addEventListener('pointermove', onMove);
            document.addEventListener('pointerup', onUp);
        },
    };
}
