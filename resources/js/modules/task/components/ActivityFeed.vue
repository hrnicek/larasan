<script setup lang="ts">
import { Deferred } from '@inertiajs/vue3';
import { Skeleton } from '@/components/ui/skeleton';
import type { TaskActivityEntry } from '@/modules/task/types';

/**
 * What has happened to this task.
 *
 * The **only** deferred region in the application, and the reason the list view in Phase 080
 * deliberately was not one: activity and comments are secondary, they can be slow, and the
 * fields above them are worth reading before they arrive.
 *
 * Comments arrive in Phase 110. Until then the region is honest — it loads, and it says there
 * is nothing yet rather than leaving a hole where something is about to appear.
 */
defineProps<{ entries?: TaskActivityEntry[] }>();
</script>

<template>
    <section>
        <h3 class="mb-1 text-xs text-muted-foreground">Activity</h3>

        <Deferred data="activity">
            <template #fallback>
                <div class="flex flex-col gap-2" aria-hidden="true">
                    <Skeleton class="h-4 w-3/4 animate-pulse" />
                    <Skeleton class="h-4 w-1/2 animate-pulse" />
                    <Skeleton class="h-4 w-2/3 animate-pulse" />
                </div>
            </template>

            <ul v-if="entries?.length" class="flex flex-col gap-1 text-sm">
                <li v-for="entry in entries" :key="entry.id" class="text-muted-foreground">
                    {{ entry.description }}
                </li>
            </ul>

            <p v-else class="text-sm text-muted-foreground">No activity yet.</p>
        </Deferred>
    </section>
</template>
