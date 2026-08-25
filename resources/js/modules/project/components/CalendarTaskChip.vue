<script setup lang="ts">
import { computed, ref } from 'vue';
import UserAvatar from '@/components/UserAvatar.vue';
import { accentDotClass } from '@/lib/accentColor';
import type { CalendarCardData } from '@/modules/task/types';

/**
 * One task in a day cell.
 *
 * A cell is a few centimetres wide and holds several of these, so a chip carries the least that
 * still identifies a task: what it is called, who has it, and a dot for what it is about. The
 * rest of what a board card shows is one click away in the panel — a chip that tried to say
 * everything would say none of it legibly.
 */
const props = defineProps<{
    card: CalendarCardData;
    /** Dragging is how the calendar reschedules, so a chip only offers it when it is allowed. */
    editable: boolean;
    dragging: boolean;
}>();

const emit = defineEmits<{
    open: [taskId: string];
    pickup: [event: PointerEvent, card: CalendarCardData];
}>();

/*
 * Where the pointer went down, so the chip can tell a click from the end of a drag. Both finish
 * with a `click` over whatever the pointer is above, and opening the panel every time somebody
 * reschedules a task would make dragging useless.
 */
const origin = ref<{ x: number; y: number } | null>(null);

const down = (event: PointerEvent): void => {
    origin.value = { x: event.clientX, y: event.clientY };

    if (props.editable) {
        emit('pickup', event, props.card);
    }
};

const activate = (event: MouseEvent): void => {
    const from = origin.value;

    origin.value = null;

    if (from !== null && Math.hypot(event.clientX - from.x, event.clientY - from.y) >= 4) {
        return;
    }

    emit('open', props.card.id);
};

/**
 * The time of day, when there is one. A due date set from the picker is midnight, and printing
 * `00:00` on every chip would be a column of noise saying nothing.
 */
const time = computed<string>(() => {
    const clock = props.card.dueAt?.slice(11, 16) ?? '';

    return clock === '00:00' ? '' : clock;
});
</script>

<template>
    <button
        type="button"
        data-calendar-chip
        :data-task-id="card.id"
        :data-placement-id="card.placementId"
        class="flex w-full items-center gap-1.5 rounded px-1.5 py-1 text-left text-xs transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
        :class="[
            card.completedAt ? 'text-muted-foreground' : 'text-foreground',
            dragging ? 'opacity-50' : '',
            editable ? 'cursor-grab touch-none active:cursor-grabbing' : '',
        ]"
        @pointerdown="down"
        @click="activate"
    >
        <span
            v-if="card.tags.length"
            class="size-1.5 shrink-0 rounded-full"
            :class="accentDotClass(card.tags[0].color)"
            :title="card.tags.map((tag) => tag.name).join(', ')"
        />

        <span class="min-w-0 flex-1 truncate" :class="card.completedAt ? 'line-through' : ''">
            {{ card.title }}
        </span>

        <span v-if="time" class="shrink-0 text-[10px] text-muted-foreground tabular-nums">{{ time }}</span>

        <UserAvatar v-if="card.assignee" :user="card.assignee" size="xs" class="shrink-0" />
    </button>
</template>
