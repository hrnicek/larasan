import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { Ref } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import { usePointerDrag } from '@/composables/usePointerDrag';
import type { CalendarCardData, CalendarDay, ProjectCalendar } from '@/modules/task/types';

export type CalendarSnapshot = { days: CalendarDay[]; undated: ProjectCalendar['undated'] };

export function useCalendarDrag(
    days: Ref<CalendarDay[]>,
    undated: Ref<ProjectCalendar['undated']>,
    enabled: () => boolean,
) {
    const draggingId = ref<string | null>(null);
    const overDay = ref<string | null>(null);
    const beginDrag = usePointerDrag();

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

    const reset = (): void => {
        draggingId.value = null;
        overDay.value = null;
    };

    return {
        draggingId,
        overDay,

        pickUp(event: PointerEvent, card: CalendarCardData): void {
            if (!enabled()) {
                return;
            }

            beginDrag(event, {
                start: () => {
                    draggingId.value = card.placementId;
                },
                move: (x, y) => {
                    overDay.value = dayUnder(x, y);
                },
                drop: (x, y) => {
                    reset();

                    const date = dayUnder(x, y);

                    if (date !== null) {
                        move(card, date);
                    }
                },
                cancel: reset,
            });
        },
    };
}
