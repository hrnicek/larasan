<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    CalendarDays,
    FileText,
    LayoutGrid,
    List,
    Paperclip,
    Pin,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Component } from 'vue';
import ProjectDefaultViewController from '@/actions/App/Http/Controllers/Project/ProjectDefaultViewController';
import { t } from '@/lib/i18n';
import { warmView } from '@/modules/project/views';
import { show } from '@/routes/projects';

const props = defineProps<{
    projectId: string;
    current: string;
    views: string[];
    defaultView: string;
    canSetDefault: boolean;
}>();

// Files and Pages are not landing views: ProjectDefaultView has no case for them.
const landingViews = ['list', 'board', 'calendar'];

const settingDefault = ref(false);

const offerDefault = computed<boolean>(
    () =>
        props.canSetDefault &&
        props.current !== props.defaultView &&
        landingViews.includes(props.current),
);

const makeDefault = (): void => {
    router.put(
        ProjectDefaultViewController.update.url(props.projectId),
        { default_view: props.current },
        {
            only: ['project'],
            preserveScroll: true,
            preserveState: true,
            onStart: () => (settingDefault.value = true),
            onFinish: () => (settingDefault.value = false),
        },
    );
};

const icons: Record<string, Component> = {
    list: List,
    board: LayoutGrid,
    calendar: CalendarDays,
    files: Paperclip,
    pages: FileText,
};

const labels: Record<string, string> = {
    list: t('List'),
    board: t('Board'),
    calendar: t('Calendar'),
    files: t('Files'),
    pages: t('Pages'),
};

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
    <div class="-mb-px flex items-end gap-2">
        <nav
            class="flex min-w-0 items-end gap-1 overflow-x-auto"
            :aria-label="t('View')"
        >
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
                <component
                    :is="icons[view]"
                    v-if="icons[view]"
                    class="size-4"
                />
                {{ labels[view] ?? view }}
            </Link>
        </nav>

        <button
            v-if="offerDefault"
            type="button"
            class="mb-1.5 inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-md px-2 text-xs text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none disabled:opacity-50 md:min-h-7"
            :disabled="settingDefault"
            @click="makeDefault"
        >
            <Pin class="size-3.5" aria-hidden="true" />
            {{ t('Set as default view') }}
        </button>
    </div>
</template>
