<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { t, tc } from '@/lib/i18n';

const page = usePage();

const unread = computed<number>(() => page.props.unreadNotifications);
const description = computed<string>(() => {
    const workspace = page.props.workspace?.name;

    if (unread.value > 0) {
        return workspace === undefined
            ? tc(
                  ':count unread in this workspace|:count unread in this workspace',
                  unread.value,
              )
            : tc(
                  ':count unread in :workspace|:count unread in :workspace',
                  unread.value,
                  { workspace },
              );
    }

    return workspace === undefined
        ? t('Everything in this workspace has been seen')
        : t('Everything in :workspace has been seen', { workspace });
});

const lineWidths = [
    'w-2/3',
    'w-1/2',
    'w-3/5',
    'w-2/5',
    'w-1/2',
    'w-3/4',
    'w-2/5',
];
</script>

<template>
    <div class="flex h-full flex-1 flex-col">
        <Head :title="t('Inbox')" />

        <PageHeader :title="t('Inbox')" :description="description" />

        <div aria-hidden="true">
            <div class="border-b border-border px-4 pt-5 pb-2 md:px-6">
                <Skeleton class="h-3.5 w-12" />
            </div>

            <div class="divide-y divide-border/70">
                <div
                    v-for="(width, index) in lineWidths"
                    :key="index"
                    class="flex items-start gap-3 px-4 py-3 md:px-6"
                >
                    <span class="size-1.5 shrink-0" />
                    <Skeleton class="size-7 shrink-0 rounded-md" />
                    <span class="flex flex-1 flex-col gap-2 pt-1">
                        <Skeleton class="h-3.5 max-w-md" :class="width" />
                        <Skeleton class="h-3 w-1/3 max-w-52" />
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
