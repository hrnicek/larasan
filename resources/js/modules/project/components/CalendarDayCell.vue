<script setup lang="ts">
import { computed } from 'vue';
import CalendarTaskChip from '@/modules/project/components/CalendarTaskChip.vue';
import InlineTaskCreate from '@/modules/task/components/InlineTaskCreate.vue';
import type { CalendarCardData, CalendarDay } from '@/modules/task/types';

/**
 * One day of the month: what is due on it, and the two things you do to a day — add a task to it
 * and open one that is already there.
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
</script>

<template>
    <td
        class="group/cell h-28 border-r border-b border-border p-1.5 align-top transition-colors"
        :class="[
            day.inMonth ? 'bg-background' : 'bg-muted/30',
            over ? 'bg-primary-subtle' : '',
        ]"
        :data-calendar-day="day.date"
    >
        <div class="flex h-full flex-col gap-1">
            <div class="flex items-center justify-between">
                <time
                    :datetime="day.date"
                    class="inline-flex size-6 items-center justify-center rounded-full text-xs font-medium tabular-nums"
                    :class="[
                        isToday ? 'bg-primary font-semibold text-primary-foreground' : '',
                        !isToday && day.inMonth ? 'text-foreground' : '',
                        !isToday && !day.inMonth ? 'text-muted-foreground' : '',
                    ]"
                >
                    {{ number }}
                </time>

                <span v-if="isToday" class="sr-only">Today</span>
            </div>

            <CalendarTaskChip
                v-for="card in day.tasks"
                :key="card.placementId"
                :card="card"
                :editable="editable"
                :dragging="draggingId === card.placementId"
                @open="emit('open', $event)"
                @pickup="(event, dragged) => emit('pickup', event, dragged)"
            />

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

            <!--
                The prompt is drawn on hover, on focus and on a touch screen, where there is no
                hover to draw it. A control that only appears under a pointer is a control a phone
                does not have.
            -->
            <InlineTaskCreate
                v-if="creatable"
                :project-id="projectId"
                :section-id="null"
                :due-at="day.date"
                compact
                class="mt-auto opacity-100 transition-opacity md:opacity-0 md:group-focus-within/cell:opacity-100 md:group-hover/cell:opacity-100"
            />
        </div>
    </td>
</template>
