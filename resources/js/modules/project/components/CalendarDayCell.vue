<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import CalendarTaskChip from '@/modules/project/components/CalendarTaskChip.vue';
import InlineTaskCreate from '@/modules/task/components/InlineTaskCreate.vue';
import type { CalendarCardData, CalendarDay } from '@/modules/task/types';

const props = defineProps<{
    day: CalendarDay;
    projectId: string;
    today: string;
    editable: boolean;
    creatable: boolean;
    draggingId: string | null;
    over: boolean;
}>();

const emit = defineEmits<{
    open: [taskId: string];
    expand: [date: string];
    pickup: [event: PointerEvent, card: CalendarCardData];
}>();

const isToday = computed<boolean>(() => props.day.date === props.today);

const number = computed<string>(() =>
    String(Number(props.day.date.slice(8, 10))),
);

const hidden = computed<number>(() => props.day.count - props.day.tasks.length);

const isWeekend = computed<boolean>(() =>
    [0, 6].includes(new Date(`${props.day.date}T00:00:00`).getDay()),
);

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

const startHere = (event: MouseEvent): void => {
    if (
        !props.creatable ||
        (event.target as HTMLElement).closest('button, input, a') !== null
    ) {
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
        <!-- Fixed height, not a minimum: a `td` grows to its content and would stretch the whole week. -->
        <div class="flex h-36 flex-col gap-0.5">
            <div class="flex items-center justify-between px-0.5">
                <time
                    :datetime="day.date"
                    class="inline-flex size-6 items-center justify-center rounded-full text-xs tabular-nums"
                    :class="[
                        isToday
                            ? 'bg-primary font-semibold text-primary-foreground'
                            : 'font-medium',
                        !isToday && day.inMonth ? 'text-foreground' : '',
                        !isToday && !day.inMonth ? 'text-muted-foreground' : '',
                    ]"
                >
                    {{ number }}
                </time>

                <span v-if="isToday" class="sr-only">Today</span>

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
