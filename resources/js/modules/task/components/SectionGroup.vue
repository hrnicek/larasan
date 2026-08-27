<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ChevronDown, Plus } from '@lucide/vue';
import { nextTick, ref } from 'vue';
import SectionController from '@/actions/App/Http/Controllers/Section/SectionController';
import EmptyState from '@/components/EmptyState.vue';
import { accentBandClass, accentDotClass, accentVars } from '@/lib/accentColor';
import SectionMenu from '@/modules/project/components/SectionMenu.vue';
import InlineTaskCreate from '@/modules/task/components/InlineTaskCreate.vue';
import TaskListSkeleton from '@/modules/task/components/TaskListSkeleton.vue';
import TaskRow from '@/modules/task/components/TaskRow.vue';
import type { ListColumn } from '@/modules/task/listColumns';
import type { TaskAssignee, TaskRowData, TaskSectionGroup } from '@/modules/task/types';
import { create } from '@/routes/tasks';

/**
 * A column of the list. The count comes from the server alongside the rows it counted, so
 * the header cannot disagree with what is drawn.
 */
const props = defineProps<{
    section: TaskSectionGroup;
    members: TaskAssignee[];
    priorities: string[];
    editable: boolean;
    creatable: boolean;
    /** What may be done to the column itself, decided by the server (ADR-0010). */
    canSection?: { create: boolean; update: boolean; delete: boolean };
    collapsed: boolean;
    loading: boolean;
    /** The row being dragged, so it can be drawn as picked up. */
    draggingId?: string | null;
    /**
     * Where a drop would land right now. Drawn as a line in the gap the row would take, because
     * a list of rows the same height is exactly where "somewhere in here" is hardest to read.
     */
    dropTarget?: { key: string; before: string | null } | null;
    projectId: string;
    /** The project's field columns, passed through to each row (TASK-150-008). */
    /** The columns after the name, in the order the project draws them (TASK-240-010). */
    columns?: ListColumn[];
    /**
     * Every group this list draws, in order, so the menu can express a move as an anchor
     * (ADR-0009). The ungrouped bucket is one of them on the screen and has no id.
     */
    siblings?: (string | null)[];
}>();

const emit = defineEmits<{
    toggle: [sectionId: string | null];
    open: [taskId: string];
    pickup: [event: PointerEvent, task: TaskRowData];
}>();

const toggle = () => emit('toggle', props.section.id);

const renaming = ref(false);
const draft = ref('');
const renameInput = ref<HTMLInputElement | null>(null);

async function startRename(): Promise<void> {
    draft.value = props.section.name ?? '';
    renaming.value = true;
    await nextTick();
    renameInput.value?.select();
}

function saveRename(): void {
    const next = draft.value.trim();

    if (props.section.id === null || next === '' || next === props.section.name) {
        renaming.value = false;

        return;
    }

    /*
     * The colour goes with the name. `PUT /sections/{section}` replaces both columns and reads an
     * absent colour as "clear it", so a rename carrying only the name silently blanks the colour.
     */
    router.put(
        SectionController.update.url(props.section.id),
        { name: next, color: props.section.color },
        { preserveScroll: true, onFinish: () => (renaming.value = false) },
    );
}

/**
 * Whether the line belongs in this gap: the pointer is over this section, and the row after the
 * gap is the one the dragged card would sit above. `null` is the gap at the end.
 */
const isDropSlot = (placementId: string | null | undefined): boolean =>
    props.dropTarget !== null
    && props.dropTarget !== undefined
    && props.dropTarget.key === (props.section.id ?? 'ungrouped')
    && props.dropTarget.before === (placementId ?? null);
</script>

<template>
    <section class="border-b border-border" data-task-section>
        <div class="group/section flex items-center gap-1 px-4 pr-2" :class="accentBandClass(section.color)" :style="accentVars(section.color)">
            <button
                type="button"
                class="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                :aria-expanded="!collapsed"
                :aria-label="collapsed ? `Expand ${section.name ?? 'No section'}` : `Collapse ${section.name ?? 'No section'}`"
                @click="toggle"
            >
                <ChevronDown class="size-4 transition-transform" :class="collapsed ? '-rotate-90' : ''" />
            </button>

            <!-- Renamed where it is read, not on a settings screen two navigations away. -->
            <input
                v-if="renaming"
                ref="renameInput"
                v-model="draft"
                type="text"
                class="min-w-0 flex-1 rounded-md border border-input bg-transparent px-1.5 py-1 text-sm font-medium focus:outline-none"
                :aria-label="`Rename ${section.name ?? 'this section'}`"
                @blur="saveRename"
                @keydown.enter.prevent="saveRename"
                @keydown.esc.prevent="renaming = false"
            />

            <button
                v-else
                type="button"
                class="flex min-w-0 flex-1 items-center gap-2 py-2 text-left text-sm font-medium"
                @click="toggle"
            >
                <!-- The colour, on the one element in the row that is always there. The band behind
                     the header says it at a glance; the dot is what survives a colour too pale to
                     read as a background. -->
                <span class="size-2 shrink-0 rounded-full" :class="accentDotClass(section.color)" :style="accentVars(section.color)" aria-hidden="true" />
                <span class="truncate">{{ section.name ?? 'No section' }}</span>
            </button>

            <span class="shrink-0 text-xs text-muted-foreground">{{ section.count }}</span>

            <SectionMenu
                v-if="canSection"
                :project-id="projectId"
                :section-id="section.id"
                :name="section.name"
                :color="section.color"
                :siblings="siblings ?? []"
                variant="list"
                :can="canSection"
                @rename="startRename"
            />

            <!--
                The third way in. It knows both the project and the column, so the form opens with
                both filled and both still editable — the inline row below adds to this column and
                nothing else, and somebody who wanted a different one would have to start again.
            -->
            <Link
                v-if="creatable"
                :href="create({ query: { project: projectId, section: section.id ?? undefined } })"
                class="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                :aria-label="`Add a task to ${section.name ?? 'No section'}`"
            >
                <Plus class="size-4" />
            </Link>
        </div>

        <TaskListSkeleton v-if="!collapsed && loading" :rows="Math.max(section.count, 1)" />

        <!-- `data-column-key` is what the drag reads back from the pointer, exactly as a board
             column does: one implementation of what a move means, two views using it. -->
        <div v-else-if="!collapsed" class="border-t border-border">
            <div class="divide-y divide-border" :data-column-key="section.id ?? 'ungrouped'">
            <template v-for="(task, position) in section.tasks" :key="task.placementId ?? task.id">
                <div v-if="isDropSlot(task.placementId)" class="relative h-0">
                    <span class="absolute inset-x-3 -top-px h-0.5 rounded-full bg-primary" aria-hidden="true" />
                </div>

                <TaskRow
                    :task="task"
                    :index="position + 1"
                    :dragging="draggingId !== null && draggingId === task.placementId"
                    :members="members"
                    :priorities="priorities"
                    :editable="editable"
                    :columns="columns"
                    @open="(taskId) => emit('open', taskId)"
                    @pickup="(event, dragged) => emit('pickup', event, dragged)"
                />
            </template>

                <!-- The end of the section is a slot too, and the only one with no row after it. -->
                <div v-if="isDropSlot(null)" class="relative h-0">
                    <span class="absolute inset-x-3 -top-px h-0.5 rounded-full bg-primary" aria-hidden="true" />
                </div>
            </div>
            <EmptyState
                v-if="section.tasks.length === 0"
                compact
                :title="`Nothing in ${section.name ?? 'this list'} yet`"
                :description="creatable ? 'Add the first one below.' : undefined"
            />

            <InlineTaskCreate
                v-if="creatable"
                :project-id="projectId"
                :section-id="section.id"
            />
        </div>
    </section>
</template>
