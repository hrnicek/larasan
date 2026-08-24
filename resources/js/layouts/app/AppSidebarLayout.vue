<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';
import SearchController from '@/actions/App/Http/Controllers/Search/SearchController';
import AppShell from '@/components/AppShell.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import ConnectionBanner from '@/components/ConnectionBanner.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    { breadcrumbs: () => [] },
);

/**
 * `⌘/Ctrl+K` opens search from any screen.
 *
 * On the layout rather than on a page, because "from any screen" is the whole requirement, and
 * a shortcut that only works where somebody remembered to add it is a shortcut nobody trusts.
 */
const onKeydown = (event: KeyboardEvent): void => {
    if (event.key !== 'k' || !(event.metaKey || event.ctrlKey)) {
        return;
    }

    event.preventDefault();

    router.get(SearchController.index.url());
};

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <AppShell>
        <ConnectionBanner />

        <Breadcrumbs v-if="breadcrumbs.length > 0" :breadcrumbs="breadcrumbs" class="px-4 pt-4 md:px-6" />

        <slot />

        <Toaster />
    </AppShell>
</template>
