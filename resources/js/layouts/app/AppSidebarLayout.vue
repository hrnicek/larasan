<script setup lang="ts">
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
</script>

<template>
    <AppShell>
        <ConnectionBanner />

        <Breadcrumbs v-if="breadcrumbs.length > 0" :breadcrumbs="breadcrumbs" class="px-4 pt-4 md:px-6" />

        <slot />

        <Toaster />
    </AppShell>
</template>
