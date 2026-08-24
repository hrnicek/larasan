<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Bell, CheckSquare, Home } from '@lucide/vue';
import { computed } from 'vue';
import InboxController from '@/actions/App/Http/Controllers/Notification/InboxController';
import MyTasksController from '@/actions/App/Http/Controllers/Task/MyTasksController';
import ChromeNavItem from '@/components/ChromeNavItem.vue';
import RealtimeStatus from '@/components/RealtimeStatus.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useInboxRealtime } from '@/composables/useRealtime';
import { useShell } from '@/composables/useShell';
import ProjectNavList from '@/modules/project/components/ProjectNavList.vue';
import WorkspaceSwitcher from '@/modules/workspace/components/WorkspaceSwitcher.vue';
import { dashboard } from '@/routes';

const page = usePage();
const { isCurrentUrl } = useCurrentUrl();
const { collapsed } = useShell();

/** The badge is the server's count, shared with every screen (TASK-130-009). */
const unread = computed<number>(() => page.props.unreadNotifications);

/*
 * The shell is the one component on every authenticated screen, so it is where the badge learns
 * about a notification without being asked (TASK-170-005). Echo is imported dynamically inside
 * the composable and is not in the entry chunk.
 */
useInboxRealtime();

const primary = computed(() => [
    { label: 'Home', href: dashboard().url, icon: Home },
    { label: 'My Tasks', href: MyTasksController.index.url(), icon: CheckSquare },
    { label: 'Inbox', href: InboxController.index.url(), icon: Bell, badge: unread.value },
]);
</script>

<template>
    <nav
        class="flex h-full flex-col gap-1 overflow-y-auto bg-chrome p-2 text-chrome-foreground"
        :class="collapsed ? 'w-14' : 'w-64'"
        aria-label="Main"
    >
        <WorkspaceSwitcher />

        <ul class="mt-1 space-y-0.5">
            <li v-for="item in primary" :key="item.label" class="relative">
                <ChromeNavItem
                    :href="item.href"
                    :label="item.label"
                    :icon="item.icon"
                    :badge="item.badge"
                    :active="isCurrentUrl(item.href)"
                    class="w-full"
                />
            </li>
        </ul>

        <hr class="my-2 border-chrome-border" />

        <ProjectNavList />

        <div class="mt-auto pt-2">
            <RealtimeStatus />
        </div>
    </nav>
</template>
