<script setup lang="ts">
import {
    ChevronLeft,
    ChevronRight,
    Columns3,
    ListChecks,
    SlidersHorizontal,
} from '@lucide/vue';
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
import ProjectColumnManager from '@/modules/project/components/ProjectColumnManager.vue';
import type { ProjectCustomize } from '@/modules/project/types';

const props = defineProps<{
    projectId: string;
    canManage: boolean;
    customize?: ProjectCustomize;
}>();

const open = ref(false);
const section = ref<'root' | 'fields' | 'columns'>('root');

// `customize` is an optional prop, so it is reloaded on every open in case it changed elsewhere.
watch(open, (isOpen) => {
    if (!isOpen) {
        section.value = 'root';

        return;
    }

    reload();
});

function reload(): void {
    reloadOptional(['customize']);
}

const headings = {
    root: {
        title: 'Customize',
        description: 'View and edit features on this project',
    },
    fields: {
        title: 'Fields',
        description:
            'What this project records about a task beyond its title and dates',
    },
    columns: {
        title: 'Columns',
        description: 'The order the list draws them in',
    },
} as const;

// Last server copy, kept because redirects omit the optional prop; null means not loaded yet.
const fields = ref<ProjectCustomize['fields'] | null>(
    props.customize?.fields ?? null,
);

const columns = ref<ProjectCustomize['columns'] | null>(
    props.customize?.columns ?? null,
);

watch(
    () => props.customize,
    (next) => {
        if (next !== undefined) {
            fields.value = next.fields;
            columns.value = next.columns;
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
                    {{ headings[section].title }}
                </SheetTitle>
                <SheetDescription>{{
                    headings[section].description
                }}</SheetDescription>
            </SheetHeader>

            <div class="px-5 py-5">
                <template v-if="section === 'root'">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 rounded-lg border border-border px-4 py-3 text-left transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                        @click="section = 'fields'"
                    >
                        <ListChecks
                            class="size-5 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="flex-1 font-medium">Fields</span>

                        <Skeleton
                            v-if="fields === null"
                            class="h-5 w-6 rounded-md"
                        />
                        <span
                            v-else
                            class="rounded-md bg-muted px-1.5 py-0.5 text-xs text-muted-foreground tabular-nums"
                        >
                            {{ fields.attached.length }}
                        </span>

                        <ChevronRight
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </button>

                    <button
                        type="button"
                        class="mt-3 flex w-full items-center gap-3 rounded-lg border border-border px-4 py-3 text-left transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                        @click="section = 'columns'"
                    >
                        <Columns3
                            class="size-5 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="flex-1 font-medium">Columns</span>

                        <Skeleton
                            v-if="columns === null"
                            class="h-5 w-6 rounded-md"
                        />
                        <span
                            v-else
                            class="rounded-md bg-muted px-1.5 py-0.5 text-xs text-muted-foreground tabular-nums"
                        >
                            {{ columns.length + 1 }}
                        </span>

                        <ChevronRight
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </button>
                </template>

                <template v-else-if="section === 'columns'">
                    <div
                        v-if="columns === null"
                        class="space-y-3"
                        aria-hidden="true"
                    >
                        <Skeleton class="h-10 w-full rounded-lg" />
                        <Skeleton class="h-10 w-full rounded-lg" />
                        <Skeleton class="h-10 w-full rounded-lg" />
                    </div>

                    <ProjectColumnManager
                        v-else
                        :project-id="props.projectId"
                        :columns="columns"
                        :can-manage="props.canManage"
                        @changed="reload"
                    />
                </template>

                <template v-else>
                    <div
                        v-if="fields === null"
                        class="space-y-3"
                        aria-hidden="true"
                    >
                        <Skeleton class="h-12 w-full rounded-lg" />
                        <Skeleton class="h-12 w-full rounded-lg" />
                        <Skeleton class="h-8 w-2/3 rounded-md" />
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
