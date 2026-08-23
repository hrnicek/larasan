<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InboxController from '@/actions/App/Http/Controllers/Notification/InboxController';
import type { InboxNotification } from '@/modules/notification/types';

/**
 * What is waiting for this person, here.
 *
 * Each line is a sentence built from the ids the notification kept and resolved by the server —
 * never from a snapshot, so a task renamed since is named as it is now.
 */
const props = defineProps<{
    notifications: InboxNotification[];
    meta: { page: number; perPage: number; total: number; hasMore: boolean; unread: number };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Inbox', href: InboxController.index.url() }] },
});

const rows = ref<InboxNotification[]>([...props.notifications]);
const loading = ref(false);

watch(() => props.notifications, (notifications) => {
    rows.value = props.meta.page === 1 ? [...notifications] : [...rows.value, ...notifications];
});

const unread = computed(() => props.meta.unread);

/** What happened, in words. The subject's name is whatever it is called now. */
const sentence = (notification: InboxNotification): string => {
    const who = notification.actor?.name ?? 'Somebody';
    const what = notification.subject?.title ?? 'something that has since been removed';

    switch (notification.type) {
        case 'task.assigned':
            return `${who} assigned you ${what}`;
        case 'comment.posted':
            return `${who} commented on ${what}`;
        default:
            return `${who} did something about ${what}`;
    }
};

const loadMore = (): void => {
    if (!props.meta.hasMore || loading.value) {
        return;
    }

    loading.value = true;

    router.get(
        InboxController.index.url({ query: { page: props.meta.page + 1 } }),
        {},
        {
            only: ['notifications', 'meta'],
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                loading.value = false;
            },
        },
    );
};
</script>

<template>
    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <Head title="Inbox" />

        <h1 class="text-sm text-muted-foreground">
            Inbox<span v-if="unread > 0"> — {{ unread }} unread</span>
        </h1>

        <ul v-if="rows.length" class="flex flex-col gap-2">
            <li v-for="notification in rows" :key="notification.id" class="flex items-baseline gap-2 text-sm">
                <span
                    class="mt-1 size-2 shrink-0 rounded-full"
                    :class="notification.read ? 'bg-transparent' : 'bg-primary'"
                    :aria-label="notification.read ? undefined : 'Unread'"
                />

                <span>{{ sentence(notification) }}</span>

                <time class="ml-auto text-xs text-muted-foreground">{{ notification.createdAt?.slice(0, 16).replace('T', ' ') }}</time>
            </li>
        </ul>

        <p v-else-if="!loading" class="text-sm text-muted-foreground">You're all caught up.</p>

        <button
            v-if="meta.hasMore"
            type="button"
            class="self-start rounded border border-input px-2 py-1 text-xs"
            :disabled="loading"
            @click="loadMore"
        >
            {{ loading ? 'Loading…' : 'Load more' }}
        </button>
    </div>
</template>
