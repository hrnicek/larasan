<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import InlineTaskCreate from '@/modules/task/components/InlineTaskCreate.vue';
import TaskListSkeleton from '@/modules/task/components/TaskListSkeleton.vue';
import TaskRow from '@/modules/task/components/TaskRow.vue';
import type { TaskAssignee, TaskSectionGroup } from '@/modules/task/types';
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
    collapsed: boolean;
    loading: boolean;
    projectId: string;
    /** The project's field columns, passed through to each row (TASK-150-008). */
    fields?: { id: string; name: string; type: string }[];
}>();

const emit = defineEmits<{
    toggle: [sectionId: string | null];
    open: [taskId: string];
}>();

const toggle = () => emit('toggle', props.section.id);
</script>

<template>
    <section class="rounded-lg border" data-task-section>
        <div class="flex items-center gap-1 pr-2">
            <button
                type="button"
                class="flex flex-1 items-center justify-between px-4 py-2 text-sm font-medium"
                :aria-expanded="!collapsed"
                @click="toggle"
            >
                <span>{{ section.name ?? 'No section' }}</span>
                <span class="text-xs text-muted-foreground">{{ section.count }}</span>
            </button>

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

        <div v-else-if="!collapsed" class="divide-y border-t">
            <TaskRow
                v-for="(task, position) in section.tasks"
                :key="task.placementId"
                :task="task"
                :index="position + 1"
                :members="members"
                :priorities="priorities"
                :editable="editable"
                :fields="fields"
                @open="(taskId) => emit('open', taskId)"
            />
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
