<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import SectionController from '@/actions/App/Http/Controllers/Section/SectionController';
import EmptyState from '@/components/EmptyState.vue';
import { accentBandClass, accentDotClass, accentVars } from '@/lib/accentColor';
import SectionMenu from '@/modules/project/components/SectionMenu.vue';
import InlineTaskCreate from '@/modules/task/components/InlineTaskCreate.vue';
import TaskCard from '@/modules/task/components/TaskCard.vue';
import type { BoardCardData, BoardColumnData } from '@/modules/task/types';

const props = defineProps<{
    column: BoardColumnData;
    projectId: string;
    editable: boolean;
    creatable: boolean;
    loading: boolean;
    draggingId: string | null;
    over: boolean;
    /** `before` is the placement the drop lands above; null means the end of the column. */
    dropTarget?: { key: string; before: string | null } | null;
    canSection?: { create: boolean; update: boolean; delete: boolean };
    columns: BoardColumnData[];
}>();

const isDropSlot = (placementId: string | null): boolean =>
    props.dropTarget != null &&
    props.dropTarget.key === (props.column.id ?? 'ungrouped') &&
    props.dropTarget.before === placementId;

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

    // The update replaces both fields and reads an absent colour as "clear", so resend the colour.
    router.put(
        SectionController.update.url(props.column.id),
        { name: next, color: props.column.color },
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
    <section
        class="flex w-72 shrink-0 flex-col rounded-lg border border-border bg-muted/30"
        data-task-section
    >
        <header
            class="group/section flex items-center gap-1 rounded-t-lg px-3 py-2.5 text-[13px] font-semibold"
            :class="accentBandClass(column.color)"
            :style="accentVars(column.color)"
        >
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
            <span
                v-else
                class="size-2 shrink-0 rounded-full"
                :class="accentDotClass(column.color)"
                :style="accentVars(column.color)"
                aria-hidden="true"
            />
            <span v-if="!renaming" class="flex-1 truncate">{{
                column.name ?? 'No section'
            }}</span>

            <span
                class="shrink-0 rounded-full bg-background px-1.5 text-[11px] font-medium text-muted-foreground"
            >
                {{ column.count }}
            </span>

            <SectionMenu
                v-if="canSection"
                :project-id="projectId"
                :section-id="column.id"
                :name="column.name"
                :color="column.color"
                :siblings="columns.map((sibling) => sibling.id)"
                variant="board"
                :can="canSection"
                @rename="startRename"
            />
        </header>

        <!-- The board's drag handler reads `data-column-key` from the element under the pointer. -->
        <div
            class="flex max-h-[60vh] min-h-24 [scrollbar-width:thin] flex-col gap-2 overflow-y-auto border-t border-border p-2"
            :class="over ? 'bg-accent/40' : ''"
            :data-column-key="column.id ?? 'ungrouped'"
        >
            <EmptyState
                v-if="column.tasks.length === 0"
                compact
                :title="creatable ? 'Nothing in this column' : 'Nothing here'"
                :description="
                    creatable
                        ? 'Drop a card here, or add one below.'
                        : undefined
                "
            />

            <template v-for="card in column.tasks" :key="card.placementId">
                <div v-if="isDropSlot(card.placementId)" class="relative h-0">
                    <span
                        class="absolute inset-x-0 -top-1 h-0.5 rounded-full bg-primary"
                        aria-hidden="true"
                    />
                </div>

                <TaskCard
                    :card="card"
                    :editable="editable"
                    :dragging="draggingId === card.placementId"
                    :columns="columns"
                    @pickup="(event, picked) => emit('pickup', event, picked)"
                    @moveto="
                        (placementId, columnKey) =>
                            emit('moveto', placementId, columnKey)
                    "
                    @open="(taskId) => emit('open', taskId)"
                />
            </template>

            <div v-if="isDropSlot(null)" class="relative h-0">
                <span
                    class="absolute inset-x-0 -top-1 h-0.5 rounded-full bg-primary"
                    aria-hidden="true"
                />
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
