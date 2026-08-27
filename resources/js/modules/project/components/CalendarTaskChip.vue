<script setup lang="ts">
import { ref } from 'vue';
import UserAvatar from '@/components/UserAvatar.vue';
import { accentDotClass, accentVars } from '@/lib/accentColor';
import type { CalendarCardData } from '@/modules/task/types';

/**
 * One task in a day cell.
 *
 * A cell is about a hundred and sixty pixels wide and holds several of these, so a chip carries
 * the least that still identifies a task: what it is called and who has it. The rest of what a
 * board card shows is one click away in the panel — a chip that tried to say everything would say
 * none of it legibly.
 *
 * No time of day. `due_at` can hold one, but nothing in this product can set one — the picker is
 * a calendar of days and the panel prints a date — so a clock on the chip would be a value the
 * reader cannot change, spending a third of the width of the cell.
 *
 * The full title is on the element as well as in it, so a truncated one is a hover away rather
 * than lost.
 */
const props = withDefaults(
    defineProps<{
        card: CalendarCardData;
        /** Dragging is how the calendar reschedules, so a chip only offers it when it is allowed. */
        editable: boolean;
        dragging: boolean;
        /**
         * `cell` is the grid's chip, packed four to a day. `row` is the same task drawn as a line
         * of the phone's agenda, where there is a whole width to use and a finger to hit it with.
         */
        variant?: 'cell' | 'row';
    }>(),
    { variant: 'cell' },
);

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

</script>

<template>
    <button
        type="button"
        data-calendar-chip
        :data-task-id="card.id"
        :data-placement-id="card.placementId"
        :title="card.title"
        class="flex w-full shrink-0 items-center rounded-sm text-left transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
        :class="[
            variant === 'row' ? 'min-h-11 gap-2 px-2 py-2 text-sm' : 'gap-1 py-0.5 pr-0.5 pl-1 text-xs',
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
            :style="accentVars(card.tags[0].color)"
            :title="card.tags.map((tag) => tag.name).join(', ')"
        />

        <span class="min-w-0 flex-1 truncate" :class="card.completedAt ? 'line-through' : ''">
            {{ card.title }}
        </span>

        <UserAvatar
            v-if="card.assignee"
            :user="card.assignee"
            :size="variant === 'row' ? 'sm' : 'xs'"
            class="shrink-0"
        />
    </button>
</template>
