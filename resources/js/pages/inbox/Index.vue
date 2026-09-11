<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { BellOff, CheckCheck } from '@lucide/vue';
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import InboxController from '@/actions/App/Http/Controllers/Notification/InboxController';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Skeleton } from '@/components/ui/skeleton';
import InboxRow from '@/modules/notification/components/InboxRow.vue';
import type { InboxNotification } from '@/modules/notification/types';
import { useTaskPanel } from '@/modules/task/composables/useTaskPanel';
import type { TaskAssignee, TaskDetail, TaskFeed } from '@/modules/task/types';

/*
 * The panel is the heaviest thing this screen can show and most visits never open one, so it is
 * not part of what the screen downloads to draw itself. `useTaskPanel` fetches it once the screen
 * is idle, which keeps opening a task instant without putting it on the critical path.
 */
const TaskDetailPanel = defineAsyncComponent(() => import('@/modules/task/components/TaskDetailPanel.vue'));

/**
 * What is waiting for this person, here.
 *
 * Each line is a sentence built from the ids the notification kept and resolved by the server —
 * never from a snapshot, so a task renamed since is named as it is now.
 */
const props = defineProps<{
    notifications: InboxNotification[];
    meta: { page: number; perPage: number; total: number; hasMore: boolean; unread: number };
    /** The panel, when the URL says one is open. `null` rather than absent (TASK-200-004). */
    taskDetail?: TaskDetail | null;
    /** Deferred with the panel: absent until the follow-up request lands. */
    activity?: TaskFeed;
    members: TaskAssignee[];
    priorities: string[];
}>();

const page = usePage();

const unread = computed<number>(() => props.meta.unread);
const workspaceName = computed<string>(() => page.props.workspace?.name ?? 'this workspace');

/*
 * The rows on screen, kept by id. A response updates the rows it carries and adds the ones the
 * screen has not seen yet — page one to the top (something arrived), a later page to the bottom
 * (Load more). Appending blindly drew a row twice whenever a page came back a second time, which
 * every mark-read does: it re-renders the page it was sent from.
 */
const rows = ref<InboxNotification[]>([...props.notifications]);

/*
 * What was unread when it reached the screen. The New group is drawn from this rather than from
 * `read`, so a line read here stays where it was, dimmed, instead of jumping into a day further
 * down while somebody is working through the list.
 */
const arrivedUnread = ref(new Set(props.notifications.filter((row) => !row.read).map((row) => row.id)));
const justArrived = ref(new Set<string>());

/*
 * How far down the list has been read, which is not what the last response said: a mark-read
 * re-renders page one after Load more has fetched page three.
 */
const loadedPage = ref(props.meta.page);
const hasMore = ref(props.meta.hasMore);
const loading = ref(false);
const loadFailed = ref(false);

watch(() => props.notifications, (incoming) => {
    const fresh = new Map(incoming.map((row) => [row.id, row]));
    const known = new Set(rows.value.map((row) => row.id));
    const kept = rows.value.map((row) => fresh.get(row.id) ?? row);
    const added = incoming.filter((row) => !known.has(row.id));

    added.filter((row) => !row.read).forEach((row) => arrivedUnread.value.add(row.id));

    if (props.meta.page >= loadedPage.value) {
        loadedPage.value = props.meta.page;
        hasMore.value = props.meta.hasMore;
    }

    if (props.meta.page > 1) {
        rows.value = [...kept, ...added];

        return;
    }

    rows.value = [...added, ...kept];

    added.forEach((row) => justArrived.value.add(row.id));
    window.setTimeout(() => added.forEach((row) => justArrived.value.delete(row.id)), 2_400);
});

/*
 * Live. The shell already listens on this person's channel and refreshes the badge; when the
 * badge knows of more unread than the list does, something arrived that the list has not drawn.
 */
watch(() => page.props.unreadNotifications, (count) => {
    if (count > props.meta.unread) {
        router.reload({ only: ['notifications', 'meta'] });
    }
});

type Group = {
    key: string;
    label: string;
    rows: InboxNotification[];
    timeStyle: 'relative' | 'clock';
};

const startOfDay = (at: Date): number => new Date(at.getFullYear(), at.getMonth(), at.getDate()).getTime();

const dayKey = (at: Date): string => `${at.getFullYear()}-${at.getMonth() + 1}-${at.getDate()}`;

/** A day the way a person would name it: nearby days by name, older ones by date. */
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

/**
 * Unread first as the server orders it, then everything else under the day it happened. The day
 * is what somebody scanning an inbox navigates by; the minute is detail.
 */
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
            earlier.set(key, { key, label: at === null ? 'Earlier' : dayLabel(at), rows: [], timeStyle: 'clock' });
        }

        earlier.get(key)!.rows.push(row);
    }

    return [
        ...(fresh.length > 0 ? [{ key: 'new', label: 'New', rows: fresh, timeStyle: 'relative' as const }] : []),
        ...earlier.values(),
    ];
});

const unreadIn = (group: Group): number => group.rows.filter((row) => !row.read).length;

const remaining = computed<number>(() => Math.min(props.meta.perPage, Math.max(props.meta.total - rows.value.length, 1)));

const { open: openTask, close: closeTask } = useTaskPanel();

const isActive = (notification: InboxNotification): boolean =>
    props.taskDetail !== null && props.taskDetail !== undefined && props.taskDetail.task.id === notification.subject?.id;

/**
 * Read state is the server's answer, so a row is not ticked off locally — the visit that follows
 * re-renders it from what came back.
 */
const markRead = (notification: InboxNotification, then?: () => void): void => {
    router.put(InboxController.read.url(notification.id), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => then?.(),
    });
};

/**
 * Clicking a line marks it read and opens what it is about — as a panel over the Inbox rather than
 * as its own page: somebody working through a list of notifications is working through a list,
 * and reading one should not cost them their place in it. Reachability is still the server's
 * `url`, and the client never decides that for itself.
 */
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

const loadMore = (): void => {
    if (!hasMore.value || loading.value) {
        return;
    }

    loading.value = true;
    loadFailed.value = false;

    router.get(
        InboxController.index.url({ query: { page: loadedPage.value + 1 } }),
        {},
        {
            only: ['notifications', 'meta'],
            preserveScroll: true,
            preserveState: true,
            /*
             * The address stays the Inbox's own. A mark-read re-renders the page it was sent from,
             * and page three of a list somebody reads from the top is not the page they are on.
             */
            preserveUrl: true,
            onHttpException: () => {
                loadFailed.value = true;

                return false;
            },
            onNetworkError: () => {
                loadFailed.value = true;
            },
            onFinish: () => {
                loading.value = false;
            },
        },
    );
};

/*
 * `↑` and `↓` walk the rows, `Enter` opens the focused one — it is a button — and `e` marks it
 * read without opening it (`docs/ui/inbox.md`). Stops at the ends rather than wrapping, as the
 * task list does.
 */
const list = ref<HTMLElement | null>(null);

const onKeydown = (event: KeyboardEvent): void => {
    if (event.metaKey || event.ctrlKey || event.altKey || list.value === null) {
        return;
    }

    const items = Array.from(list.value.querySelectorAll<HTMLElement>('[data-inbox-id]'));
    const current = (event.target as HTMLElement).closest<HTMLElement>('[data-inbox-id]');
    const index = current === null ? -1 : items.indexOf(current);

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        if (items.length === 0) {
            return;
        }

        event.preventDefault();

        const next = event.key === 'ArrowDown' ? index + 1 : index - 1;

        items[Math.min(Math.max(next, 0), items.length - 1)]?.querySelector<HTMLElement>('[data-inbox-row]')?.focus();

        return;
    }

    if (event.key === 'e' && current !== null) {
        const notification = rows.value.find((row) => row.id === current.dataset.inboxId);

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
            :description="unread > 0 ? `${unread} unread in ${workspaceName}` : `Everything in ${workspaceName} has been seen`"
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
            <section v-for="group in groups" :key="group.key" :aria-labelledby="`inbox-group-${group.key}`">
                <h2
                    :id="`inbox-group-${group.key}`"
                    class="sticky top-0 z-10 flex items-center gap-2 border-b border-border bg-background px-4 pt-5 pb-2 text-[13px] font-semibold tracking-wide text-muted-foreground md:px-6"
                >
                    <!-- Some locales write a weekday in lower case; a heading starts with a capital. -->
                    <span class="inline-block first-letter:uppercase">{{ group.label }}</span>
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

            <div v-if="loading" class="border-t border-border/70" aria-hidden="true">
                <div v-for="line in 3" :key="line" class="flex items-start gap-3 px-4 py-3 md:px-6">
                    <span class="size-1.5 shrink-0" />
                    <Skeleton class="size-7 shrink-0 rounded-md" />
                    <span class="flex flex-1 flex-col gap-2 pt-1">
                        <Skeleton class="h-3.5 w-2/3 max-w-md" />
                        <Skeleton class="h-3 w-1/3 max-w-52" />
                    </span>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-3 px-4 md:px-6">
                <p v-if="loadFailed" class="flex items-center gap-2 text-sm text-muted-foreground" role="status">
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

                <p class="ml-auto hidden items-center gap-1.5 text-xs text-muted-foreground md:flex">
                    <kbd class="inbox-key">↑</kbd><kbd class="inbox-key">↓</kbd> move
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
