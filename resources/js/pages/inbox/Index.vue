<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { BellOff, CheckCheck } from '@lucide/vue';
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import InboxController from '@/actions/App/Http/Controllers/Notification/InboxController';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { usePagedRows } from '@/composables/usePagedRows';
import InboxRow from '@/modules/notification/components/InboxRow.vue';
import type { InboxNotification } from '@/modules/notification/types';
import { useTaskPanel } from '@/modules/task/composables/useTaskPanel';
import type { TaskAssignee, TaskDetail, TaskFeed } from '@/modules/task/types';

const TaskDetailPanel = defineAsyncComponent(
    () => import('@/modules/task/components/TaskDetailPanel.vue'),
);

const props = defineProps<{
    notifications: InboxNotification[];
    meta: {
        page: number;
        perPage: number;
        total: number;
        hasMore: boolean;
        unread: number;
    };
    taskDetail?: TaskDetail | null;
    /** Deferred; absent until the follow-up request lands. */
    activity?: TaskFeed;
    members: TaskAssignee[];
    priorities: string[];
}>();

const page = usePage();

const unread = computed<number>(() => props.meta.unread);
const workspaceName = computed<string>(
    () => page.props.workspace?.name ?? 'this workspace',
);

// The New group is drawn from unread-on-arrival rather than `read`, so a row read here stays in place.
const arrivedUnread = ref(
    new Set(
        props.notifications.filter((row) => !row.read).map((row) => row.id),
    ),
);
const justArrived = ref(new Set<string>());

const { rows, hasMore, loading, loadFailed, loadMore } = usePagedRows({
    rows: () => props.notifications,
    meta: () => props.meta,
    url: (number) => InboxController.index.url({ query: { page: number } }),
    only: ['notifications', 'meta'],
    placement: 'stable',
    onAdded: (added, number) => {
        added
            .filter((row) => !row.read)
            .forEach((row) => arrivedUnread.value.add(row.id));

        if (number > 1) {
            return;
        }

        added.forEach((row) => justArrived.value.add(row.id));
        window.setTimeout(
            () => added.forEach((row) => justArrived.value.delete(row.id)),
            2_400,
        );
    },
});

// The shell's realtime listener refreshes the badge; a higher count than the list's means new rows arrived.
watch(
    () => page.props.unreadNotifications,
    (count) => {
        if (count > props.meta.unread) {
            router.reload({ only: ['notifications', 'meta'] });
        }
    },
);

type Group = {
    key: string;
    label: string;
    rows: InboxNotification[];
    timeStyle: 'relative' | 'clock';
};

const startOfDay = (at: Date): number =>
    new Date(at.getFullYear(), at.getMonth(), at.getDate()).getTime();

const dayKey = (at: Date): string =>
    `${at.getFullYear()}-${at.getMonth() + 1}-${at.getDate()}`;

const dayLabel = (at: Date): string => {
    const now = new Date();
    const daysAgo = Math.round((startOfDay(now) - startOfDay(at)) / 86_400_000);

    if (daysAgo === 0) {
        return 'Today';
    }

    if (daysAgo === 1) {
        return 'Yesterday';
    }

    if (daysAgo > 1 && daysAgo < 7) {
        return at.toLocaleDateString(undefined, { weekday: 'long' });
    }

    return at.toLocaleDateString(undefined, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: at.getFullYear() === now.getFullYear() ? undefined : 'numeric',
    });
};

const groups = computed<Group[]>(() => {
    const fresh = rows.value.filter((row) => arrivedUnread.value.has(row.id));
    const earlier = new Map<string, Group>();

    for (const row of rows.value) {
        if (arrivedUnread.value.has(row.id)) {
            continue;
        }

        const at = row.createdAt === null ? null : new Date(row.createdAt);
        const key = at === null ? 'undated' : dayKey(at);

        if (!earlier.has(key)) {
            earlier.set(key, {
                key,
                label: at === null ? 'Earlier' : dayLabel(at),
                rows: [],
                timeStyle: 'clock',
            });
        }

        earlier.get(key)!.rows.push(row);
    }

    return [
        ...(fresh.length > 0
            ? [
                  {
                      key: 'new',
                      label: 'New',
                      rows: fresh,
                      timeStyle: 'relative' as const,
                  },
              ]
            : []),
        ...earlier.values(),
    ];
});

const unreadIn = (group: Group): number =>
    group.rows.filter((row) => !row.read).length;

const remaining = computed<number>(() =>
    Math.min(
        props.meta.perPage,
        Math.max(props.meta.total - rows.value.length, 1),
    ),
);

const { open: openTask, close: closeTask } = useTaskPanel();

const isActive = (notification: InboxNotification): boolean =>
    props.taskDetail !== null &&
    props.taskDetail !== undefined &&
    props.taskDetail.task.id === notification.subject?.id;

const markRead = (notification: InboxNotification, then?: () => void): void => {
    router.put(
        InboxController.read.url(notification.id),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => then?.(),
        },
    );
};

const openNotification = (notification: InboxNotification): void => {
    const subject = notification.subject;

    if (subject === null || subject.url === null) {
        return;
    }

    if (notification.read) {
        openTask(subject.id);

        return;
    }

    markRead(notification, () => openTask(subject.id));
};

const markAllRead = (): void => {
    router.put(InboxController.readAll.url(), {}, { preserveScroll: true });
};

const list = ref<HTMLElement | null>(null);

const onKeydown = (event: KeyboardEvent): void => {
    if (event.metaKey || event.ctrlKey || event.altKey || list.value === null) {
        return;
    }

    const items = Array.from(
        list.value.querySelectorAll<HTMLElement>('[data-inbox-id]'),
    );
    const current = (event.target as HTMLElement).closest<HTMLElement>(
        '[data-inbox-id]',
    );
    const index = current === null ? -1 : items.indexOf(current);

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        if (items.length === 0) {
            return;
        }

        event.preventDefault();

        const next = event.key === 'ArrowDown' ? index + 1 : index - 1;

        items[Math.min(Math.max(next, 0), items.length - 1)]
            ?.querySelector<HTMLElement>('[data-inbox-row]')
            ?.focus();

        return;
    }

    if (event.key === 'e' && current !== null) {
        const notification = rows.value.find(
            (row) => row.id === current.dataset.inboxId,
        );

        if (notification !== undefined && !notification.read) {
            event.preventDefault();
            markRead(notification);
        }
    }
};
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
        >
            <template v-if="unread > 0" #actions>
                <button
                    type="button"
                    class="inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-2.5 text-[13px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    @click="markAllRead"
                >
                    <CheckCheck class="size-4" />
                    Mark all read
                </button>
            </template>
        </PageHeader>

        <div
            v-if="rows.length"
            ref="list"
            class="flex flex-1 flex-col pb-6"
            :aria-busy="loading"
            @keydown="onKeydown"
        >
            <section
                v-for="group in groups"
                :key="group.key"
                :aria-labelledby="`inbox-group-${group.key}`"
            >
                <h2
                    :id="`inbox-group-${group.key}`"
                    class="sticky top-0 z-10 flex items-center gap-2 border-b border-border bg-background px-4 pt-5 pb-2 text-[13px] font-semibold tracking-wide text-muted-foreground md:px-6"
                >
                    <!-- Some locales write weekdays in lower case. -->
                    <span class="inline-block first-letter:uppercase">{{
                        group.label
                    }}</span>
                    <span
                        v-if="group.key === 'new' && unreadIn(group) > 0"
                        class="rounded-full bg-primary px-1.5 py-px text-[11px] leading-4 font-medium text-primary-foreground tabular-nums"
                    >
                        {{ unreadIn(group) }}
                    </span>
                </h2>

                <ul class="divide-y divide-border/70">
                    <InboxRow
                        v-for="notification in group.rows"
                        :key="notification.id"
                        :notification="notification"
                        :active="isActive(notification)"
                        :arrived="justArrived.has(notification.id)"
                        :time-style="group.timeStyle"
                        @open="openNotification"
                        @mark-read="markRead"
                    />
                </ul>
            </section>

            <div
                v-if="loading"
                class="border-t border-border/70"
                aria-hidden="true"
            >
                <div
                    v-for="line in 3"
                    :key="line"
                    class="flex items-start gap-3 px-4 py-3 md:px-6"
                >
                    <span class="size-1.5 shrink-0" />
                    <Skeleton class="size-7 shrink-0 rounded-md" />
                    <span class="flex flex-1 flex-col gap-2 pt-1">
                        <Skeleton class="h-3.5 w-2/3 max-w-md" />
                        <Skeleton class="h-3 w-1/3 max-w-52" />
                    </span>
                </div>
            </div>

            <div
                class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-3 px-4 md:px-6"
            >
                <p
                    v-if="loadFailed"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                    role="status"
                >
                    Older notifications did not load.
                    <button
                        type="button"
                        class="font-medium text-foreground underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                        @click="loadMore"
                    >
                        Try again
                    </button>
                </p>

                <button
                    v-else-if="hasMore && !loading"
                    type="button"
                    class="inline-flex h-8 items-center rounded-md border border-border px-3 text-[13px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    @click="loadMore"
                >
                    Show {{ remaining }} older
                </button>

                <p
                    class="ml-auto hidden items-center gap-1.5 text-xs text-muted-foreground md:flex"
                >
                    <kbd class="inbox-key">↑</kbd
                    ><kbd class="inbox-key">↓</kbd> move
                    <kbd class="inbox-key ml-2">Enter</kbd> open
                    <kbd class="inbox-key ml-2">E</kbd> mark read
                </p>
            </div>
        </div>

        <EmptyState
            v-else
            class="mx-4 mt-6 md:mx-6"
            :icon="BellOff"
            title="You're all caught up"
            description="Comments, assignments and mentions land here. Nothing is waiting."
        />

        <TaskDetailPanel
            v-if="taskDetail"
            :key="taskDetail.task.id"
            :detail="taskDetail"
            :members="members"
            :priorities="priorities"
            :activity="activity"
            @open="openTask"
            @close="closeTask"
        />
    </div>
</template>

<style scoped>
.inbox-key {
    display: inline-flex;
    min-width: 1.25rem;
    height: 1.25rem;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--color-border);
    border-radius: 4px;
    padding: 0 0.3rem;
    font-family: inherit;
    font-size: 11px;
    font-weight: 500;
    color: var(--color-foreground);
}
</style>
