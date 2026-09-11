<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Skeleton } from '@/components/ui/skeleton';

const page = usePage();

/*
 * The shell's badge is the same count the Inbox reports, so its sentence can be written before
 * the list arrives and the header does not change height when it does.
 */
const unread = computed<number>(() => page.props.unreadNotifications);
const workspaceName = computed<string>(
    () => page.props.workspace?.name ?? 'this workspace',
);

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
        <Head title="Inbox" />

        <PageHeader
            title="Inbox"
            :description="
                unread > 0
                    ? `${unread} unread in ${workspaceName}`
                    : `Everything in ${workspaceName} has been seen`
            "
        />

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
