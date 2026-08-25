<script setup lang="ts">
import { CalendarDays } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import CalendarDayCell from '@/modules/project/components/CalendarDayCell.vue';
import CalendarTaskChip from '@/modules/project/components/CalendarTaskChip.vue';
import type { CalendarCardData, CalendarDay, ProjectCalendar } from '@/modules/task/types';

/**
 * The month itself.
 *
 * Two shapes rather than one that stretches. From `md` it is the table everybody means by
 * "calendar": seven columns, whole weeks, every day drawn whether or not it holds anything.
 * Below `md` seven columns is seven columns nobody can read, so the same month is drawn as an
 * agenda of the days that hold something — which is what a phone can show and what somebody on
 * one is looking for.
 */
const props = defineProps<{
    calendar: ProjectCalendar;
    projectId: string;
    editable: boolean;
    creatable: boolean;
    draggingId: string | null;
    /** The day a drop would land on, as `Y-m-d`. */
    overDay: string | null;
    /** True while another month is on its way, so the one on screen can step back rather than blink. */
    loading: boolean;
}>();

const emit = defineEmits<{
    open: [taskId: string];
    expand: [date: string];
    pickup: [event: PointerEvent, card: CalendarCardData];
}>();

/** The reader's own weekday names, taken from the first week the grid draws. */
const weekdays = computed<{ date: string; label: string }[]> (() =>
    props.calendar.days.slice(0, 7).map((day) => ({
        date: day.date,
        label: new Date(`${day.date}T00:00:00`).toLocaleDateString(undefined, { weekday: 'short' }),
    })),
);

const withTasks = computed<CalendarDay[]>(() => props.calendar.days.filter((day) => day.tasks.length > 0));

/**
 * The month in rows of seven.
 *
 * A table rather than a grid of boxes: a cell of a calendar means *this weekday, this week*, and
 * a column header a reader is never told about is a column header only the sighted have. The
 * server sends whole weeks, so the chunking cannot leave a short row.
 */
const weeks = computed<CalendarDay[][]>(() => {
    const rows: CalendarDay[][] = [];

    for (let start = 0; start < props.calendar.days.length; start += 7) {
        rows.push(props.calendar.days.slice(start, start + 7));
    }

    return rows;
});

const monthLabel = computed<string>(() =>
    new Date(`${props.calendar.month}-01T00:00:00`).toLocaleDateString(undefined, { month: 'long', year: 'numeric' }),
);

/** Why the month is empty, said once — the line on a desktop and the panel on a phone share it. */
const emptyDetail = computed<string>(() =>
    props.calendar.undated.count > 0
        ? `${props.calendar.undated.count} task${props.calendar.undated.count === 1 ? '' : 's'} in this project have no due date yet.`
        : 'Give a task a due date and it appears on the day it is due.',
);

const dayLabel = (date: string): string =>
    new Date(`${date}T00:00:00`).toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' });
</script>

<template>
    <div class="flex flex-col">
        <table
            class="hidden w-full table-fixed border-collapse border-t border-l border-border transition-opacity md:table"
            :class="loading ? 'pointer-events-none opacity-60' : ''"
        >
            <caption class="sr-only">{{ monthLabel }}</caption>

            <thead>
                <tr>
                    <th
                        v-for="weekday in weekdays"
                        :key="weekday.date"
                        scope="col"
                        class="border-r border-b border-border bg-muted/40 px-1.5 py-1.5 text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{ weekday.label }}
                    </th>
                </tr>
            </thead>

            <tbody>
                <tr v-for="week in weeks" :key="week[0].date">
                    <CalendarDayCell
                        v-for="day in week"
                        :key="day.date"
                        :day="day"
                        :project-id="projectId"
                        :today="calendar.today"
                        :editable="editable"
                        :creatable="creatable"
                        :dragging-id="draggingId"
                        :over="overDay === day.date"
                        @open="emit('open', $event)"
                        @expand="emit('expand', $event)"
                        @pickup="(event, card) => emit('pickup', event, card)"
                    />
                </tr>
            </tbody>
        </table>

        <!-- The phone's month: the days that hold something, in order, with the same chips. -->
        <div v-if="withTasks.length" class="flex flex-col divide-y divide-border border-y border-border md:hidden">
            <section v-for="day in withTasks" :key="day.date" class="flex flex-col gap-0.5 px-2 py-3">
                <h3
                    class="px-2 pb-1 text-xs font-semibold tracking-wide uppercase"
                    :class="day.date === calendar.today ? 'text-primary' : 'text-muted-foreground'"
                >
                    {{ dayLabel(day.date) }}
                    <span v-if="day.date === calendar.today" class="normal-case">· today</span>
                </h3>

                <CalendarTaskChip
                    v-for="card in day.tasks"
                    :key="card.placementId"
                    :card="card"
                    :editable="false"
                    :dragging="false"
                    variant="row"
                    @open="emit('open', $event)"
                />

                <button
                    v-if="day.hasMore"
                    type="button"
                    class="min-h-11 self-start rounded px-2 text-left text-[13px] font-medium text-muted-foreground hover:text-foreground"
                    @click="emit('expand', day.date)"
                >
                    +{{ day.count - day.tasks.length }} more
                </button>
            </section>
        </div>

        <!-- The grid says "empty" by being empty, so on a desktop the month needs a line rather
             than a panel. Below `md` there is no grid to read, and the panel is the whole answer. -->
        <p v-if="!withTasks.length" class="hidden px-4 py-3 text-sm text-muted-foreground md:block md:px-6">
            Nothing is due in {{ monthLabel }}. {{ emptyDetail }}
        </p>

        <EmptyState
            v-if="!withTasks.length"
            class="mx-4 mt-4 md:hidden"
            :icon="CalendarDays"
            :title="`Nothing is due in ${monthLabel}`"
            :description="emptyDetail"
        />
    </div>
</template>
