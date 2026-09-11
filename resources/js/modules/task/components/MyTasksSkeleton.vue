<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Skeleton } from '@/components/ui/skeleton';
import TaskListSkeleton from '@/modules/task/components/TaskListSkeleton.vue';

const workspaceName = computed<string | undefined>(
    () => usePage().props.workspace?.name,
);

const tabWidths = ['w-10', 'w-16', 'w-14', 'w-16', 'w-12'];
</script>

<template>
    <div class="flex h-full flex-1 flex-col">
        <Head title="My Tasks" />

        <PageHeader title="My Tasks" :description="workspaceName">
            <template #tabs>
                <div class="flex h-9 items-end gap-1" aria-hidden="true">
                    <span
                        v-for="(width, index) in tabWidths"
                        :key="index"
                        class="px-3 pb-2.5"
                    >
                        <Skeleton class="h-4" :class="width" />
                    </span>
                </div>
            </template>
        </PageHeader>

        <TaskListSkeleton :rows="8" aria-hidden="true" />
    </div>
</template>
