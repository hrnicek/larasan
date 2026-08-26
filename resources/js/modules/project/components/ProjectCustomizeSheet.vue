<script setup lang="ts">
import { ChevronLeft, ChevronRight, ListChecks, SlidersHorizontal } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { reloadOptional } from '@/lib/optionalProps';
import ProjectFieldManager from '@/modules/custom-field/components/ProjectFieldManager.vue';
import type { ProjectCustomize } from '@/modules/project/types';

/**
 * What this project records, changed from the project itself rather than from its settings screen.
 *
 * A drawer over the board rather than a page away from it: choosing what a project records is
 * something somebody decides *while looking at the work* — the column they wish were there is on
 * the screen behind this. The same controls still live on the settings screen, because that is
 * where somebody goes who is setting a project up rather than using one.
 *
 * Two levels, as the reader's own reference shows: the list of what can be customized, and the
 * one thing they picked. `Fields` is the only entry today; the shape is what makes a second one
 * an addition rather than a redesign.
 */
const props = defineProps<{
    projectId: string;
    canManage: boolean;
    customize?: ProjectCustomize;
}>();

const open = ref(false);
const section = ref<'root' | 'fields'>('root');

/*
 * The drawer's contents are `Inertia::optional`, so they are asked for when it is opened rather
 * than sent to everybody who opens a project. Asked for every time: a field could have been
 * defined in another tab since the last look, and the list is small enough that re-reading it is
 * cheaper than being wrong about it.
 */
watch(open, (isOpen) => {
    if (!isOpen) {
        section.value = 'root';

        return;
    }

    reload();
});

/** The one place the drawer's own props are asked for, so opening and writing agree. */
function reload(): void {
    reloadOptional(['customize']);
}

/*
 * The last list the server sent, kept while the next one is on its way.
 *
 * A write inside the drawer redirects, and the page that comes back does not carry an optional
 * prop — so reading `props.customize` directly would blank the list on every attach and draw a
 * skeleton over something the reader had just changed. Holding the last answer means the drawer
 * shows the old list for the length of one request instead of nothing at all, and `null` keeps its
 * real meaning: not read yet.
 */
const fields = ref<ProjectCustomize['fields'] | null>(props.customize?.fields ?? null);

watch(
    () => props.customize,
    (next) => {
        if (next !== undefined) {
            fields.value = next.fields;
        }
    },
    { immediate: true },
);
</script>

<template>
    <Sheet v-model:open="open">
        <SheetTrigger as-child>
            <Button variant="outline" size="sm">
                <SlidersHorizontal class="size-4" />
                Customize
            </Button>
        </SheetTrigger>

        <SheetContent class="w-full gap-0 overflow-y-auto p-0 sm:max-w-md">
            <SheetHeader class="border-b border-border px-5 py-4">
                <SheetTitle class="flex items-center gap-2 text-lg">
                    <Button
                        v-if="section !== 'root'"
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Back to Customize"
                        @click="section = 'root'"
                    >
                        <ChevronLeft class="size-4" />
                    </Button>
                    {{ section === 'root' ? 'Customize' : 'Fields' }}
                </SheetTitle>
                <SheetDescription>
                    {{
                        section === 'root'
                            ? 'View and edit features on this project'
                            : 'What this project records about a task beyond its title and dates'
                    }}
                </SheetDescription>
            </SheetHeader>

            <div class="px-5 py-5">
                <template v-if="section === 'root'">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 rounded-lg border border-border px-4 py-3 text-left transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                        @click="section = 'fields'"
                    >
                        <ListChecks class="size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <span class="flex-1 font-medium">Fields</span>

                        <!-- The count is what the drawer is for at a glance; a skeleton rather
                             than a zero, because "none yet" and "not read yet" are different. -->
                        <Skeleton v-if="fields === null" class="h-5 w-6 rounded-md" />
                        <span
                            v-else
                            class="rounded-md bg-muted px-1.5 py-0.5 text-xs text-muted-foreground tabular-nums"
                        >
                            {{ fields.attached.length }}
                        </span>

                        <ChevronRight class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    </button>
                </template>

                <template v-else>
                    <div v-if="fields === null" class="space-y-3" aria-hidden="true">
                        <Skeleton class="h-12 w-full animate-pulse rounded-lg" />
                        <Skeleton class="h-12 w-full animate-pulse rounded-lg" />
                        <Skeleton class="h-8 w-2/3 animate-pulse rounded-md" />
                    </div>

                    <ProjectFieldManager
                        v-else
                        :project-id="props.projectId"
                        :attached="fields.attached"
                        :available="fields.available"
                        :can-manage="props.canManage"
                        @changed="reload"
                    />
                </template>
            </div>
        </SheetContent>
    </Sheet>
</template>
