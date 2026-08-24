<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import InlineTaskCreate from '@/modules/task/components/InlineTaskCreate.vue';
import TaskCard from '@/modules/task/components/TaskCard.vue';
import type { BoardCardData, BoardColumnData } from '@/modules/task/types';

/**
 * One column. It scrolls on its own, so a long column does not push the board's other columns
 * off the screen, and it says what it is not showing rather than pretending to be complete.
 */
defineProps<{
    column: BoardColumnData;
    projectId: string;
    editable: boolean;
    creatable: boolean;
    loading: boolean;
    draggingId: string | null;
    over: boolean;
    columns: BoardColumnData[];
}>();

const emit = defineEmits<{
    expand: [columnId: string | null];
    pickup: [event: PointerEvent, card: BoardCardData];
    moveto: [placementId: string, columnKey: string];
    open: [taskId: string];
}>();
</script>

<template>
    <section class="flex shrink-0 flex-col rounded-lg border" data-task-section>
        <header class="flex items-center justify-between px-3 py-2 text-sm font-medium">
            <span>{{ column.name ?? 'No section' }}</span>
            <span class="text-xs text-muted-foreground">{{ column.count }}</span>
        </header>

        <!-- The drop target. `data-column-key` is what the drag reads back from the pointer. -->
        <div
            class="flex max-h-[60vh] min-h-24 flex-col gap-2 overflow-y-auto border-t p-2"
            :class="over ? 'bg-accent/40' : ''"
            :data-column-key="column.id ?? 'ungrouped'"
        >
            <EmptyState
                v-if="column.tasks.length === 0"
                compact
                :title="creatable ? 'Nothing in this column' : 'Nothing here'"
                :description="creatable ? 'Drop a card here, or add one below.' : undefined"
            />

            <TaskCard
                v-for="card in column.tasks"
                :key="card.placementId"
                :card="card"
                :editable="editable"
                :dragging="draggingId === card.placementId"
                :columns="columns"
                @pickup="(event, picked) => emit('pickup', event, picked)"
                @moveto="(placementId, columnKey) => emit('moveto', placementId, columnKey)"
                @open="(taskId) => emit('open', taskId)"
            />

            <button
                v-if="column.hasMore"
                type="button"
                class="rounded border border-dashed px-3 py-2 text-xs text-muted-foreground hover:text-foreground disabled:opacity-50"
                :disabled="loading"
                @click="emit('expand', column.id)"
            >
                Show all {{ column.count }}
            </button>
        </div>

        <InlineTaskCreate
            v-if="creatable"
            :project-id="projectId"
            :section-id="column.id"
        />
    </section>
</template>
