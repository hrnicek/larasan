import { router, usePage } from '@inertiajs/vue3';
import type { Component, ComputedRef, Ref } from 'vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import SettingsSkeleton from '@/components/SettingsSkeleton.vue';
import InboxSkeleton from '@/modules/notification/components/InboxSkeleton.vue';
import PageScreenSkeleton from '@/modules/page/components/PageScreenSkeleton.vue';
import ProjectIndexSkeleton from '@/modules/project/components/ProjectIndexSkeleton.vue';
import ProjectScreenSkeleton from '@/modules/project/components/ProjectScreenSkeleton.vue';
import ProjectSettingsSkeleton from '@/modules/project/components/ProjectSettingsSkeleton.vue';
import SearchSkeleton from '@/modules/search/components/SearchSkeleton.vue';
import MyTasksSkeleton from '@/modules/task/components/MyTasksSkeleton.vue';
import TaskPageSkeleton from '@/modules/task/components/TaskPageSkeleton.vue';
import WorkspacesSkeleton from '@/modules/workspace/components/WorkspacesSkeleton.vue';

type Level = 'shell' | 'settings';

type Screen = { awaits: string; skeleton: Component; within?: Level };

// An instant visit carries only shared props, so each awaited prop must be one the server never shares.
const screens: Record<string, Screen> = {
    'projects/Show': { awaits: 'project', skeleton: ProjectScreenSkeleton },
    'projects/Index': { awaits: 'allProjects', skeleton: ProjectIndexSkeleton },
    'projects/Settings': {
        awaits: 'project',
        skeleton: ProjectSettingsSkeleton,
    },
    'my-tasks/Index': { awaits: 'tasks', skeleton: MyTasksSkeleton },
    'inbox/Index': { awaits: 'notifications', skeleton: InboxSkeleton },
    'tasks/Show': { awaits: 'task', skeleton: TaskPageSkeleton },
    'pages/Show': { awaits: 'page', skeleton: PageScreenSkeleton },
    'search/Index': { awaits: 'tasks', skeleton: SearchSkeleton },
    'workspaces/Index': { awaits: 'invitations', skeleton: WorkspacesSkeleton },
    'settings/Profile': {
        awaits: 'avatarPresets',
        skeleton: SettingsSkeleton,
        within: 'settings',
    },
    'settings/Workspace': {
        awaits: 'can',
        skeleton: SettingsSkeleton,
        within: 'settings',
    },
    'settings/Members': {
        awaits: 'invitations',
        skeleton: SettingsSkeleton,
        within: 'settings',
    },
    'settings/Fields': {
        awaits: 'fields',
        skeleton: SettingsSkeleton,
        within: 'settings',
    },
    'settings/Tags': {
        awaits: 'tags',
        skeleton: SettingsSkeleton,
        within: 'settings',
    },
    'settings/Security': {
        awaits: 'passwordRules',
        skeleton: SettingsSkeleton,
        within: 'settings',
    },
    'settings/Appearance': {
        awaits: 'uiThemes',
        skeleton: SettingsSkeleton,
        within: 'settings',
    },
};

function waitingFor(component: string, props: object): Screen | null {
    const screen = screens[component];

    return screen !== undefined && !(screen.awaits in props) ? screen : null;
}

export function usePendingSkeleton(
    level: Level,
): ComputedRef<Component | null> {
    const page = usePage();

    return computed<Component | null>(() => {
        const screen = waitingFor(page.component, page.props);

        return screen !== null && (screen.within ?? 'shell') === level
            ? screen.skeleton
            : null;
    });
}

// Called once, by the shell, which outlives every screen and so sees every visit.
export function usePendingScreen(): {
    skeleton: ComputedRef<Component | null>;
    failed: Ref<boolean>;
    retry: () => void;
} {
    const page = usePage();

    const skeleton = usePendingSkeleton('shell');
    const waiting = computed<boolean>(
        () => waitingFor(page.component, page.props) !== null,
    );

    const failed = ref(false);

    watch(waiting, (current) => {
        if (!current) {
            failed.value = false;
        }
    });

    const retry = (): void => {
        failed.value = false;
        router.reload();
    };

    const stops: Array<() => void> = [];

    onMounted(() => {
        stops.push(
            router.on('start', (event) => {
                if (!event.detail.visit.prefetch) {
                    failed.value = false;
                }
            }),
            // A prefetch finishes before its visit has drawn anything, so it cannot mark a failure.
            router.on('finish', (event) => {
                if (!event.detail.visit.prefetch && waiting.value) {
                    failed.value = true;
                }
            }),
            // History can restore an abandoned instant visit that never received its props.
            router.on('navigate', (event) => {
                if (event.detail.visitId === undefined && waiting.value) {
                    router.reload();
                }
            }),
        );
    });

    onUnmounted(() => stops.forEach((stop) => stop()));

    return { skeleton, failed, retry };
}
