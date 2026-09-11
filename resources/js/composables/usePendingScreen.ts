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

/**
 * The screens a link opens instantly, each named with the prop that proves its own props landed.
 *
 * An instant visit (Inertia v3) swaps to the next screen before the server has answered, carrying
 * the shared props alone. These screens cannot draw themselves from those, so their skeleton is
 * drawn until the awaited prop arrives. It has to be a prop the server never shares — which is
 * why `settings/Workspace` waits on `can` rather than on its `workspace`, and the workspace list
 * on `invitations` rather than on `workspaces`: both of those names are shared as well.
 * `tests/Feature/Navigation/InstantScreensTest.php` holds the same pairs to that.
 *
 * A settings screen is drawn inside the settings layout, which keeps its navigation on screen and
 * draws the skeleton in the column the screen will fill.
 */
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

/** The skeleton the given layout draws instead of its page, or `null` when the page can draw itself. */
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

/**
 * The shell's half: its own skeleton, and whether the visit that should have filled a waiting
 * screen — at any level — failed.
 *
 * Called once, by the shell: it is the one component that outlives every screen, so its router
 * listeners see the visit that leaves a screen waiting as well as the one that fills it.
 */
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
            /*
             * A visit that ended with the screen still waiting did not bring its props — a network
             * error, or an answer that was not a page — and nothing else will ask again. A prefetch
             * finishes before the visit that uses it has drawn anything, so it proves nothing.
             */
            router.on('finish', (event) => {
                if (!event.detail.visit.prefetch && waiting.value) {
                    failed.value = true;
                }
            }),
            /*
             * Back or forward onto an instant visit that was abandoned for another restores a page
             * that never got its props, and history does not ask the server. A navigation with no
             * visit behind it is exactly that case.
             */
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
