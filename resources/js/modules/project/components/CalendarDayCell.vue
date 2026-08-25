<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import CalendarTaskChip from '@/modules/project/components/CalendarTaskChip.vue';
import InlineTaskCreate from '@/modules/task/components/InlineTaskCreate.vue';
import type { CalendarCardData, CalendarDay } from '@/modules/task/types';

/**
 * One day of the month: what is due on it, and the two things you do to a day — add a task to it
 * and open one that is already there.
 *
 * The cell is a fixed height and never grows. A month whose rows stretch to fit their busiest day
 * is a month where one deadline pushes the other three weeks off the screen; a full day says how
 * many tasks it did not draw instead.
 *
 * The days outside the month are drawn dimmed rather than blanked. A card due on the 31st of the
 * previous month is work this week, and a grid that hid it would be showing the calendar's idea
 * of a month instead of the reader's.
 */
const props = defineProps<{
    day: CalendarDay;
    projectId: string;
    today: string;
    editable: boolean;
    creatable: boolean;
    /** The card being dragged, so the chip it came from can dim while it travels. */
    draggingId: string | null;
    /** True while this cell is what a drop would land on. */
    over: boolean;
}>();

const emit = defineEmits<{
    open: [taskId: string];
    expand: [date: string];
    pickup: [event: PointerEvent, card: CalendarCardData];
}>();

const isToday = computed<boolean>(() => props.day.date === props.today);

/** Just the number: the month is named above the grid, and every cell repeating it is noise. */
const number = computed<string>(() => String(Number(props.day.date.slice(8, 10))));

const hidden = computed<number>(() => props.day.count - props.day.tasks.length);

/** Saturday and Sunday, tinted — a week reads faster when its ends are visible without counting. */
const isWeekend = computed<boolean>(() => [0, 6].includes(new Date(`${props.day.date}T00:00:00`).getDay()));

/**
 * Four surfaces, in the order they win: the day a drop would land on, today, a day of another
 * month, and the weekend. `muted` reads as *recessed* in light and merely as *other* in dark,
 * which is the same message either way — this is not a working day of this month.
 */
const surface = computed<string>(() => {
    if (props.over || isToday.value) {
        return 'bg-primary-subtle';
    }

    if (!props.day.inMonth) {
        return 'bg-muted';
    }

    return isWeekend.value ? 'bg-muted/50' : 'bg-background';
});

const composer = ref<InstanceType<typeof InlineTaskCreate> | null>(null);

/**
 * Clicking the empty part of a day starts a task on it. The whole cell is the hit area, because
 * aiming at a prompt that appears under the pointer means aiming at something that was not there
 * a moment ago — the prompt stays as the affordance and the cell carries the target.
 */
const startHere = (event: MouseEvent): void => {
    if (!props.creatable || (event.target as HTMLElement).closest('button, input, a') !== null) {
        return;
    }

    composer.value?.start();
};
</script>

<template>
    <td
        class="group/cell border-r border-b border-border p-1 align-top transition-colors"
        :class="[surface, over ? 'ring-2 ring-primary-ring ring-inset' : '']"
        :data-calendar-day="day.date"
        @click="startHere"
    >
        <!-- A fixed height rather than a minimum: a `td` grows to its content, and one busy day
             would otherwise set the height of its whole week. -->
        <div class="flex h-36 flex-col gap-0.5">
            <div class="flex items-center justify-between px-0.5">
                <time
                    :datetime="day.date"
                    class="inline-flex size-6 items-center justify-center rounded-full text-xs tabular-nums"
                    :class="[
                        isToday ? 'bg-primary font-semibold text-primary-foreground' : 'font-medium',
                        !isToday && day.inMonth ? 'text-foreground' : '',
                        !isToday && !day.inMonth ? 'text-muted-foreground' : '',
                    ]"
                >
                    {{ number }}
                </time>

                <span v-if="isToday" class="sr-only">Today</span>

                <!-- Adding lives in the header, where it costs the cell no height. The whole day
                     is a hit area for the same thing; this is what makes it discoverable. -->
                <button
                    v-if="creatable"
                    type="button"
                    class="inline-flex size-5 items-center justify-center rounded text-muted-foreground transition-opacity hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none md:opacity-0 md:group-focus-within/cell:opacity-100 md:group-hover/cell:opacity-100"
                    :aria-label="`Add a task due ${day.date}`"
                    @click="composer?.start()"
                >
                    <Plus class="size-3.5" aria-hidden="true" />
                </button>
            </div>

            <!-- The chips scroll inside the cell rather than stretching it. At rest a day holds at
                 most `PER_DAY` of them and nothing scrolls; a day the reader has opened in full is
                 where this earns its place. -->
            <div class="flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto">
                <CalendarTaskChip
                    v-for="card in day.tasks"
                    :key="card.placementId"
                    :card="card"
                    :editable="editable"
                    :dragging="draggingId === card.placementId"
                    @open="emit('open', $event)"
                    @pickup="(event, dragged) => emit('pickup', event, dragged)"
                />
            </div>

            <!-- What the cell knows it is not showing. The server states the number, so this is a
                 fact rather than an inference from a page size. -->
            <button
                v-if="day.hasMore"
                type="button"
                class="rounded px-1.5 py-0.5 text-left text-[11px] font-medium text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                @click="emit('expand', day.date)"
            >
                +{{ hidden }} more
            </button>

            <InlineTaskCreate
                v-if="creatable"
                ref="composer"
                :project-id="projectId"
                :section-id="null"
                :due-at="day.date"
                compact
                hide-trigger
            />
        </div>
    </td>
</template>
