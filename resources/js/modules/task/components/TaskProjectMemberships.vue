<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronDown, Plus, X } from '@lucide/vue';
import { ref } from 'vue';
import PlacementController from '@/actions/App/Http/Controllers/Placement/PlacementController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { accentDotClass, accentVars } from '@/lib/accentColor';
import TaskSectionHeading from '@/modules/task/components/TaskSectionHeading.vue';
import type { TaskDetail } from '@/modules/task/types';

/**
 * Where this task appears, and the only screen where a person can change it.
 *
 * Removing the last project is allowed (ADR-0003): a task with no project is still a task. The
 * warning is honest about what changes — it becomes reachable from My Tasks and search rather
 * than from a board — instead of pretending the task is about to be lost.
 *
 * The block holds whatever a project asks of this task, which is why the fields render inside it
 * rather than under a heading of their own: a custom field exists because a project defines it.
 */
const props = defineProps<{
    taskId: string;
    placements: TaskDetail['placements'];
    availableProjects: TaskDetail['availableProjects'];
    editable: boolean;
}>();

const working = ref(false);

/**
 * Which column of that project the task sits in.
 *
 * The move endpoint takes a section and, optionally, where in it — this sends only the section,
 * so the card lands at the end of the column. Dropping it at a chosen place between two cards is
 * the board's job and the board already does it (ADR-0009).
 *
 * A null section is the ungrouped bucket rather than the absence of an answer (ADR-0004), which
 * is why the menu offers it as an entry of its own.
 */
const move = (placementId: string, sectionId: string | null): void => {
    working.value = true;

    router.put(
        PlacementController.move.url(placementId),
        { section: sectionId },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                working.value = false;
            },
        },
    );
};

const attach = (projectId: string): void => {
    working.value = true;

    router.post(
        PlacementController.store.url(projectId),
        { task: props.taskId },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                working.value = false;
            },
        },
    );
};

/*
 * Taking a task out of its last project does not delete it — it stays reachable from My Tasks and
 * from search — but from this screen it looks like disappearance, which is exactly the case worth
 * asking about (ADR-0013).
 */
const detaching = ref<{ placementId: string; name: string } | null>(null);

const detach = (): void => {
    if (detaching.value === null) {
        return;
    }

    working.value = true;

    router.delete(PlacementController.destroy.url(detaching.value.placementId), {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            working.value = false;
            detaching.value = null;
        },
    });
};
</script>

<template>
    <section class="flex flex-col gap-1">
        <TaskSectionHeading title="Projects" :count="placements.length ? String(placements.length) : null">
            <template v-if="editable && availableProjects.length" #add>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            class="size-7 text-muted-foreground"
                            :disabled="working"
                            aria-label="Add this task to a project"
                        >
                            <Plus class="size-4" />
                        </Button>
                    </DropdownMenuTrigger>

                    <DropdownMenuContent align="start" class="w-56">
                        <DropdownMenuItem
                            v-for="project in availableProjects"
                            :key="project.id"
                            @select="attach(project.id)"
                        >
                            {{ project.name }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
        </TaskSectionHeading>

        <ul v-if="placements.length" class="flex flex-col">
            <li
                v-for="placement in placements"
                :key="placement.placementId"
                class="group/placement flex items-center gap-2 border-b border-border py-2"
            >
                <span
                    class="size-2.5 shrink-0 rounded-sm"
                    :class="accentDotClass(placement.project.color)"
                    :style="accentVars(placement.project.color)"
                    aria-hidden="true"
                />

                <span class="truncate text-sm font-medium">{{ placement.project.name }}</span>

                <DropdownMenu v-if="editable && placement.canChange">
                    <DropdownMenuTrigger
                        class="inline-flex min-w-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-xs tracking-wide text-muted-foreground uppercase transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none disabled:opacity-50"
                        :disabled="working"
                        :aria-label="`Move this task to another section of ${placement.project.name}`"
                    >
                        <span class="truncate">{{ placement.section?.name ?? 'No section' }}</span>
                        <ChevronDown class="size-3 shrink-0" aria-hidden="true" />
                    </DropdownMenuTrigger>

                    <DropdownMenuContent align="start" class="w-56">
                        <DropdownMenuItem
                            :class="placement.section === null ? 'bg-accent' : ''"
                            @select="move(placement.placementId, null)"
                        >
                            No section
                        </DropdownMenuItem>

                        <DropdownMenuItem
                            v-for="section in placement.sections"
                            :key="section.id"
                            :class="section.id === placement.section?.id ? 'bg-accent' : ''"
                            @select="move(placement.placementId, section.id)"
                        >
                            {{ section.name }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>

                <span v-else class="truncate text-xs tracking-wide text-muted-foreground uppercase">
                    {{ placement.section?.name ?? 'No section' }}
                </span>

                <Button
                    v-if="editable && placement.canChange"
                    variant="ghost"
                    size="icon-sm"
                    class="ml-auto size-7 text-muted-foreground focus-visible:opacity-100 md:opacity-0 md:group-hover/placement:opacity-100"
                    :disabled="working"
                    :aria-label="`Remove from ${placement.project.name}`"
                    :title="
                        placements.length === 1
                            ? 'This is the last project — the task will only be reachable from My Tasks and search.'
                            : undefined
                    "
                    @click="detaching = { placementId: placement.placementId, name: placement.project.name }"
                >
                    <X class="size-4" />
                </Button>
            </li>
        </ul>

        <p v-else class="border-b border-border py-2 text-sm text-muted-foreground">
            In no project — reachable from My Tasks and search.
        </p>

        <!-- What the projects above ask of this task. Empty is said rather than left blank: a gap
             here reads as a screen that failed to draw something. -->
        <div class="py-2">
            <slot name="fields" />
        </div>

        <ConfirmDialog
            :open="detaching !== null"
            :title="`Remove from ${detaching?.name}?`"
            description="The task itself is not deleted. If this was its last project it stays reachable from My Tasks and from search."
            confirm-label="Remove"
            cancel-label="Keep it"
            :pending="working"
            @update:open="(next) => !next && (detaching = null)"
            @confirm="detach"
        />
    </section>
</template>
