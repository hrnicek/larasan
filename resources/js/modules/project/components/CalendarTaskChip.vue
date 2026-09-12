<script setup lang="ts">
import { ref } from 'vue';
import UserAvatar from '@/components/UserAvatar.vue';
import { accentDotClass, accentVars } from '@/lib/accentColor';
import type { CalendarCardData } from '@/modules/task/types';

const props = withDefaults(
    defineProps<{
        card: CalendarCardData;
        editable: boolean;
        dragging: boolean;
        variant?: 'cell' | 'row';
    }>(),
    { variant: 'cell' },
);

const emit = defineEmits<{
    open: [taskId: string];
    pickup: [event: PointerEvent, card: CalendarCardData];
}>();

// A drag also ends in `click`, so the pointer-down position tells a click from a drop.
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
