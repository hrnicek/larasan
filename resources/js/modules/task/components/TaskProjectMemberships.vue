<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PlacementController from '@/actions/App/Http/Controllers/Placement/PlacementController';
import ProjectPlacementController from '@/actions/App/Http/Controllers/Placement/PlacementController';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { TaskDetail } from '@/modules/task/types';

/**
 * Where this task appears, and the only screen where a person can change it.
 *
 * Removing the last project is allowed (ADR-0003): a task with no project is still a task.
 * The warning is honest about what changes — it becomes reachable from My Tasks and search
 * rather than from a board — instead of pretending the task is about to be lost.
 */
const props = defineProps<{
    taskId: string;
    placements: TaskDetail['placements'];
    availableProjects: TaskDetail['availableProjects'];
    editable: boolean;
}>();

const working = ref(false);

const attach = (projectId: string): void => {
    working.value = true;

    router.post(
        ProjectPlacementController.store.url(projectId),
        { task: props.taskId },
        { preserveScroll: true, preserveState: true, onFinish: () => {
 working.value = false; 
} },
    );
};

const detach = (placementId: string): void => {
    working.value = true;

    router.delete(PlacementController.destroy.url(placementId), {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
 working.value = false; 
},
    });
};
</script>

<template>
    <section>
        <h3 class="mb-1 text-xs text-muted-foreground">Projects</h3>

        <ul v-if="placements.length" class="flex flex-col gap-1 text-sm">
            <li v-for="placement in placements" :key="placement.placementId" class="flex items-center gap-2">
                <span>{{ placement.project.name }}</span>
                <span class="text-muted-foreground">· {{ placement.section?.name ?? 'No section' }}</span>

                <button
                    v-if="editable && placement.canDetach"
                    type="button"
                    class="ml-auto rounded px-1 text-xs text-muted-foreground hover:text-foreground disabled:opacity-50"
                    :disabled="working"
                    :aria-label="`Remove from ${placement.project.name}`"
                    :title="placements.length === 1 ? 'This is the last project — the task will only be reachable from My Tasks and search.' : undefined"
                    @click="detach(placement.placementId)"
                >
                    Remove
                </button>
            </li>
        </ul>

        <p v-else class="text-sm text-muted-foreground">
            In no project — reachable from My Tasks and search.
        </p>

        <DropdownMenu v-if="editable && availableProjects.length">
            <DropdownMenuTrigger
                class="mt-2 rounded border border-dashed px-2 py-1 text-xs text-muted-foreground hover:text-foreground disabled:opacity-50"
                :disabled="working"
            >
                + Add to project
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
                <DropdownMenuItem
                    v-for="project in availableProjects"
                    :key="project.id"
                    @select="attach(project.id)"
                >
                    {{ project.name }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </section>
</template>
