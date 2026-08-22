<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import { useCollapsedSections } from '@/composables/useCollapsedSections';
import { useTaskListKeyboard } from '@/composables/useTaskListKeyboard';
import ProjectHeader from '@/modules/project/components/ProjectHeader.vue';
import InlineTaskCreate from '@/modules/task/components/InlineTaskCreate.vue';
import SectionGroup from '@/modules/task/components/SectionGroup.vue';
import type { ProjectList, TaskAssignee } from '@/modules/task/types';

/**
 * The project's own screen. The board arrives in Phase 090; until then the switcher is
 * honest about it rather than rendering a list under the wrong name.
 */
const props = defineProps<{
    project: { id: string; name: string; slug: string; color: string | null; icon: string | null; archived: boolean };
    view: string;
    views: string[];
    list: ProjectList;
    members: TaskAssignee[];
    priorities: string[];
}>();

const editable = () => props.list.can.updateTask;

const { isCollapsed, toggle } = useCollapsedSections(props.project.id);

// Named for the element, not the prop: `list` is already the payload.
const listElement = ref<HTMLElement | null>(null);
const { onKeydown } = useTaskListKeyboard(() => listElement.value);

/*
 * The rows are part of the page rather than a deferred region: the whole list is one query
 * and one round trip, and deferring the main content would trade a fast page for a spinner.
 * What does take time is a reload of the list alone — the retry after an error, and whatever
 * later asks for more rows — so the skeleton stands in for exactly that.
 */
const reloading = ref(false);
const failed = ref(false);
const listening = (event: { detail: { visit: { only: string[] } } }) => event.detail.visit.only.includes('list');

const started = (event: { detail: { visit: { only: string[] } } }) => {
    if (listening(event)) {
        reloading.value = true;
        failed.value = false;
    }
};

const finished = () => {
    reloading.value = false;
};

/*
 * Inertia v3's names: `invalid` became `httpException` and `exception` became `networkError`.
 * Both leave the rows that are already drawn alone — an error region that emptied the screen
 * would lose the reader's place to tell them something went wrong.
 */
const errored = () => {
    failed.value = true;
    reloading.value = false;
};

const retry = () => {
    router.reload({ only: ['list'] });
};

const stops: Array<() => void> = [];

onMounted(() => {
    stops.push(
        router.on('start', started),
        router.on('finish', finished),
        router.on('httpException', errored),
        router.on('networkError', errored),
    );
});

onUnmounted(() => {
    stops.forEach((stop) => stop());
});
</script>

<template>
    <div class="flex flex-col space-y-6">
        <Head :title="project.name" />

        <ProjectHeader :project="project" :view="view" :views="views" />

        <p v-if="view === 'board'" class="rounded-lg border border-dashed px-4 py-6 text-sm text-muted-foreground">
            The board view is not built yet. Switch to the list to see this project's tasks.
        </p>

        <template v-else>
            <div
                v-if="failed"
                class="flex items-center justify-between rounded-lg border border-destructive/40 px-4 py-3 text-sm"
                role="alert"
            >
                <span>Something went wrong loading this project.</span>
                <button type="button" class="underline" @click="retry">Try again</button>
            </div>

            <div
                v-if="list.sections.length"
                ref="listElement"
                class="flex flex-col space-y-4"
                @keydown="onKeydown"
            >
                <SectionGroup
                    v-for="section in list.sections"
                    :key="section.id ?? 'ungrouped'"
                    :section="section"
                    :members="members"
                    :priorities="priorities"
                    :editable="editable()"
                    :creatable="list.can.createTask"
                    :project-id="project.id"
                    :collapsed="isCollapsed(section.id)"
                    :loading="reloading"
                    @toggle="toggle"
                />
            </div>

            <div v-else class="flex flex-col items-center gap-3 rounded-lg border border-dashed px-4 py-10 text-center">
                <p class="text-sm text-muted-foreground">This project has no tasks and no columns yet.</p>
                <InlineTaskCreate v-if="list.can.createTask" :project-id="project.id" :section-id="null" />
            </div>
        </template>

    </div>
</template>
