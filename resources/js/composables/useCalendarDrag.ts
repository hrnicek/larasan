import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { Ref } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import { perFrame } from '@/lib/perFrame';
import type { CalendarCardData, CalendarDay, ProjectCalendar } from '@/modules/task/types';

/** Pixels of pointer travel below which a press is a click, not a drag. */
const THRESHOLD = 4;

export type CalendarSnapshot = { days: CalendarDay[]; undated: ProjectCalendar['undated'] };

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

    const dayUnder = (x: number, y: number): string | null => {
        const cell = document.elementFromPoint(x, y)?.closest<HTMLElement>('[data-calendar-day]');

        return cell?.dataset.calendarDay ?? null;
    };

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

        if ('date' in origin && origin.date === date) {
            return;
        }

        const previous = snapshot();

        origin.tasks = origin.tasks.filter((held) => held.placementId !== card.placementId);
        origin.count -= 1;

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

            const track = perFrame((x: number, y: number): void => {
                overDay.value = dayUnder(x, y);
            });

            const onMove = (moved: PointerEvent): void => {
                if (!dragging && Math.hypot(moved.clientX - startX, moved.clientY - startY) < THRESHOLD) {
                    return;
                }

                dragging = true;
                draggingId.value = card.placementId;

                track.call(moved.clientX, moved.clientY);
            };

            const onUp = (up: PointerEvent): void => {
                document.removeEventListener('pointermove', onMove);
                document.removeEventListener('pointerup', onUp);
                track.cancel();

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

            document.addEventListener('pointermove', onMove, { passive: true });
            document.addEventListener('pointerup', onUp, { passive: true });
        },
    };
}
