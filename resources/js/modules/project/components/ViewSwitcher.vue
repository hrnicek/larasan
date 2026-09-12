<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarDays, FileText, LayoutDashboard, LayoutGrid, List, Paperclip } from '@lucide/vue';
import type { Component } from 'vue';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { warmView } from '@/modules/project/views';
import { show } from '@/routes/projects';

const props = defineProps<{
    projectId: string;
    current: string;
    views: string[];
}>();

const icons: Record<string, Component> = {
    list: List,
    board: LayoutGrid,
    calendar: CalendarDays,
    files: Paperclip,
    pages: FileText,
};

const planned: { label: string; icon: Component }[] = [{ label: 'Dashboard', icon: LayoutDashboard }];

const isCurrent = (view: string): boolean => view === props.current;

// Instant visit: cleared view payloads make `projects/Show` draw skeletons; the task panel closes.
const switchingTo =
    (view: string) =>
    (current: Record<string, unknown>): Record<string, unknown> => ({
        ...current,
        view,
        list: undefined,
        board: undefined,
        calendar: undefined,
        files: undefined,
        pages: undefined,
        taskDetail: null,
        activity: undefined,
    });
</script>

<template>
    <nav class="-mb-px flex items-end gap-1 overflow-x-auto" aria-label="View">
        <Link
            v-for="view in views"
            :key="view"
            :href="show(projectId, { query: { view } }).url"
            :component="isCurrent(view) ? undefined : 'projects/Show'"
            :page-props="isCurrent(view) ? undefined : switchingTo(view)"
            :prefetch="isCurrent(view) ? false : 'click'"
            :aria-current="isCurrent(view) ? 'page' : undefined"
            class="inline-flex shrink-0 items-center gap-1.5 border-b-2 px-3 pt-1 pb-2.5 text-sm font-medium capitalize transition-colors focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
            :class="
                isCurrent(view)
                    ? 'border-primary text-foreground'
                    : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground'
            "
            @pointerenter="warmView(view)"
            @focus="warmView(view)"
        >
            <component :is="icons[view]" v-if="icons[view]" class="size-4" />
            {{ view }}
        </Link>

        <Tooltip v-for="option in planned" :key="option.label">
            <TooltipTrigger
                disabled
                class="inline-flex shrink-0 cursor-not-allowed items-center gap-1.5 border-b-2 border-transparent px-3 pt-1 pb-2.5 text-sm font-medium text-muted-foreground/50"
            >
                <component :is="option.icon" class="size-4" />
                {{ option.label }}
            </TooltipTrigger>
            <TooltipContent side="bottom">Not built yet</TooltipContent>
        </Tooltip>
    </nav>
</template>
