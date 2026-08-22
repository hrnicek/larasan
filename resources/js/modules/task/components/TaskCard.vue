<script setup lang="ts">
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { BoardCardData, BoardColumnData } from '@/modules/task/types';

/**
 * One card on the board. Comment counts arrive with Phase 110 and tag colours with Phase 140;
 * the card ships without them rather than waiting for tables that do not exist.
 */
defineProps<{
    card: BoardCardData;
    editable: boolean;
    dragging: boolean;
    columns: BoardColumnData[];
}>();

const emit = defineEmits<{
    pickup: [event: PointerEvent, card: BoardCardData];
    moveto: [placementId: string, columnKey: string];
    open: [taskId: string];
}>();

const keyOf = (column: BoardColumnData): string => column.id ?? 'ungrouped';
</script>

<template>
    <article
        tabindex="0"
        data-task-card
        :data-task-id="card.id"
        :data-placement-id="card.placementId"
        class="rounded-md border bg-card p-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
        :class="[
            card.completedAt ? 'text-muted-foreground' : '',
            dragging ? 'opacity-50' : '',
            editable ? 'cursor-grab touch-none active:cursor-grabbing' : '',
        ]"
        @pointerdown="editable ? emit('pickup', $event, card) : undefined"
        @keydown.enter="emit('open', card.id)"
    >
        <button
            type="button"
            class="mb-2 line-clamp-3 text-left"
            :class="card.completedAt ? 'line-through' : ''"
            @click="emit('open', card.id)"
        >
            {{ card.title }}
        </button>

        <div class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
            <span v-if="card.assignee">{{ card.assignee.name }}</span>
            <span v-if="card.dueAt">{{ card.dueAt.slice(0, 10) }}</span>
            <span class="capitalize">{{ card.priority }}</span>
            <span v-if="card.subtasks > 0">{{ card.subtasks }} subtasks</span>
            <span v-if="card.comments > 0">{{ card.comments }} comments</span>

            <!--
                Moving without dragging: the phone's path, where a drag across a pager is a
                gesture nobody can land — and a perfectly good one with a mouse too.
            -->
            <DropdownMenu v-if="editable">
                <DropdownMenuTrigger class="ml-auto rounded px-1 hover:text-foreground" aria-label="Move to column">
                    Move…
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        v-for="column in columns"
                        :key="keyOf(column)"
                        @select="emit('moveto', card.placementId, keyOf(column))"
                    >
                        {{ column.name ?? 'No section' }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </article>
</template>
