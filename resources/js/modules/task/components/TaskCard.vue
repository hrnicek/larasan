<script setup lang="ts">
import type { BoardCardData } from '@/modules/task/types';

/**
 * One card on the board. Comment counts arrive with Phase 110 and tag colours with Phase 140;
 * the card ships without them rather than waiting for tables that do not exist.
 */
defineProps<{
    card: BoardCardData;
    editable: boolean;
    dragging: boolean;
}>();

const emit = defineEmits<{ pickup: [event: PointerEvent, card: BoardCardData] }>();
</script>

<template>
    <article
        tabindex="0"
        data-task-card
        :data-placement-id="card.placementId"
        class="rounded-md border bg-card p-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
        :class="[
            card.completedAt ? 'text-muted-foreground' : '',
            dragging ? 'opacity-50' : '',
            editable ? 'cursor-grab touch-none active:cursor-grabbing' : '',
        ]"
        @pointerdown="editable ? emit('pickup', $event, card) : undefined"
    >
        <p class="mb-2 line-clamp-3" :class="card.completedAt ? 'line-through' : ''">{{ card.title }}</p>

        <div class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
            <span v-if="card.assignee">{{ card.assignee.name }}</span>
            <span v-if="card.dueAt">{{ card.dueAt.slice(0, 10) }}</span>
            <span class="capitalize">{{ card.priority }}</span>
            <span v-if="card.subtasks > 0">{{ card.subtasks }} subtasks</span>
        </div>
    </article>
</template>
