<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import SectionController from '@/actions/App/Http/Controllers/Section/SectionController';
import EmptyState from '@/components/EmptyState.vue';
import SectionMenu from '@/modules/project/components/SectionMenu.vue';
import InlineTaskCreate from '@/modules/task/components/InlineTaskCreate.vue';
import TaskCard from '@/modules/task/components/TaskCard.vue';
import type { BoardCardData, BoardColumnData } from '@/modules/task/types';

/**
 * One column. It scrolls on its own, so a long column does not push the board's other columns
 * off the screen, and it says what it is not showing rather than pretending to be complete.
 */
const props = defineProps<{
    column: BoardColumnData;
    projectId: string;
    editable: boolean;
    creatable: boolean;
    loading: boolean;
    draggingId: string | null;
    over: boolean;
    /** Where a drop would land right now, drawn as a line in the gap the card would take. */
    dropTarget?: { key: string; before: string | null } | null;
    /** What may be done to the column itself, decided by the server (ADR-0010). */
    canSection?: { create: boolean; update: boolean; delete: boolean };
    columns: BoardColumnData[];
}>();

/**
 * Whether the line belongs in this gap: the pointer is over this column, and the card after the
 * gap is the one the dragged card would sit above. `null` is the gap at the end.
 */
const isDropSlot = (placementId: string | null): boolean =>
    props.dropTarget != null
    && props.dropTarget.key === (props.column.id ?? 'ungrouped')
    && props.dropTarget.before === placementId;

const renaming = ref(false);
const draft = ref('');
const renameInput = ref<HTMLInputElement | null>(null);

async function startRename(): Promise<void> {
    draft.value = props.column.name ?? '';
    renaming.value = true;
    await nextTick();
    renameInput.value?.select();
}

function saveRename(): void {
    const next = draft.value.trim();

    if (props.column.id === null || next === '' || next === props.column.name) {
        renaming.value = false;

        return;
    }

    router.put(
        SectionController.update.url(props.column.id),
        { name: next },
        { preserveScroll: true, onFinish: () => (renaming.value = false) },
    );
}

const emit = defineEmits<{
    expand: [columnId: string | null];
    pickup: [event: PointerEvent, card: BoardCardData];
    moveto: [placementId: string, columnKey: string];
    open: [taskId: string];
}>();
</script>

<template>
    <section class="flex w-72 shrink-0 flex-col rounded-lg border border-border bg-muted/30" data-task-section>
        <header class="group/section flex items-center gap-1 px-3 py-2.5 text-[13px] font-semibold">
            <input
                v-if="renaming"
                ref="renameInput"
                v-model="draft"
                type="text"
                class="min-w-0 flex-1 rounded-md border border-input bg-transparent px-1.5 py-0.5 text-[13px] font-semibold focus:outline-none"
                :aria-label="`Rename ${column.name ?? 'this column'}`"
                @blur="saveRename"
                @keydown.enter.prevent="saveRename"
                @keydown.esc.prevent="renaming = false"
            />
            <span v-else class="flex-1 truncate">{{ column.name ?? 'No section' }}</span>

            <span class="shrink-0 rounded-full bg-background px-1.5 text-[11px] font-medium text-muted-foreground">
                {{ column.count }}
            </span>

            <SectionMenu
                v-if="canSection"
                :project-id="projectId"
                :section-id="column.id"
                :name="column.name"
                :can="canSection"
                @rename="startRename"
            />
        </header>

        <!-- The drop target. `data-column-key` is what the drag reads back from the pointer. -->
        <div
            class="flex max-h-[60vh] min-h-24 flex-col gap-2 overflow-y-auto border-t border-border p-2 [scrollbar-width:thin]"
            :class="over ? 'bg-accent/40' : ''"
            :data-column-key="column.id ?? 'ungrouped'"
        >
            <EmptyState
                v-if="column.tasks.length === 0"
                compact
                :title="creatable ? 'Nothing in this column' : 'Nothing here'"
                :description="creatable ? 'Drop a card here, or add one below.' : undefined"
            />

            <template v-for="card in column.tasks" :key="card.placementId">
                <div v-if="isDropSlot(card.placementId)" class="relative h-0">
                    <span class="absolute inset-x-0 -top-1 h-0.5 rounded-full bg-primary" aria-hidden="true" />
                </div>

                <TaskCard
                    :card="card"
                    :editable="editable"
                    :dragging="draggingId === card.placementId"
                    :columns="columns"
                    @pickup="(event, picked) => emit('pickup', event, picked)"
                    @moveto="(placementId, columnKey) => emit('moveto', placementId, columnKey)"
                    @open="(taskId) => emit('open', taskId)"
                />
            </template>

            <!-- The end of the column is a slot too, and the only one with no card after it. -->
            <div v-if="isDropSlot(null)" class="relative h-0">
                <span class="absolute inset-x-0 -top-1 h-0.5 rounded-full bg-primary" aria-hidden="true" />
            </div>

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
