<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarDays, LayoutDashboard, LayoutGrid, List, Paperclip } from '@lucide/vue';
import type { Component } from 'vue';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { show } from '@/routes/projects';

/**
 * The views a project has, and the ones it does not have yet.
 *
 * The view lives in the URL rather than in local state, so a reload and a shared link both show
 * what the sender saw, and each option is a real link for the same reason.
 *
 * The three that are not built are **shown disabled with a reason** rather than hidden or, worse,
 * linked to an empty screen. A tab that is absent reads as "this product does not do that"; a tab
 * that is disabled and says why reads as "not yet", which is the truth.
 *
 * Timeline is absent rather than disabled. It needs dependencies drawn against a time axis, which
 * is further off than the other three, and a greyed tab is still a promise.
 */
const props = defineProps<{
    projectId: string;
    current: string;
    views: string[];
}>();

const icons: Record<string, Component> = {
    list: List,
    board: LayoutGrid,
};

const planned: { label: string; icon: Component }[] = [
    { label: 'Calendar', icon: CalendarDays },
    { label: 'Dashboard', icon: LayoutDashboard },
    { label: 'Files', icon: Paperclip },
];

const isCurrent = (view: string): boolean => view === props.current;
</script>

<template>
    <nav class="-mb-px flex items-end gap-1 overflow-x-auto" aria-label="View">
        <Link
            v-for="view in views"
            :key="view"
            :href="show(projectId, { query: { view } }).url"
            :aria-current="isCurrent(view) ? 'page' : undefined"
            class="inline-flex shrink-0 items-center gap-1.5 border-b-2 px-3 pt-1 pb-2.5 text-sm font-medium capitalize transition-colors focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
            :class="
                isCurrent(view)
                    ? 'border-primary text-foreground'
                    : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground'
            "
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
