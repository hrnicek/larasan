import { router, usePage } from '@inertiajs/vue3';
import type { Component, ComputedRef, Ref } from 'vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import InboxSkeleton from '@/modules/notification/components/InboxSkeleton.vue';
import ProjectIndexSkeleton from '@/modules/project/components/ProjectIndexSkeleton.vue';
import ProjectScreenSkeleton from '@/modules/project/components/ProjectScreenSkeleton.vue';
import MyTasksSkeleton from '@/modules/task/components/MyTasksSkeleton.vue';

/**
 * The screens a link opens instantly, each named with the prop that proves its own props landed.
 *
 * An instant visit (Inertia v3) swaps to the next screen before the server has answered, carrying
 * the shared props alone. These screens cannot draw themselves from those, so the shell draws
 * their skeleton until the awaited prop arrives. It has to be a prop the server never shares —
 * `tests/Feature/Navigation/InstantScreensTest.php` holds the same four pairs to that.
 */
const screens: Record<string, { awaits: string; skeleton: Component }> = {
    'projects/Show': { awaits: 'project', skeleton: ProjectScreenSkeleton },
    'projects/Index': { awaits: 'allProjects', skeleton: ProjectIndexSkeleton },
    'my-tasks/Index': { awaits: 'tasks', skeleton: MyTasksSkeleton },
    'inbox/Index': { awaits: 'notifications', skeleton: InboxSkeleton },
};

/**
 * The skeleton the shell should draw instead of the page, or `null` when the page can draw itself.
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

    const skeleton = computed<Component | null>(() => {
        const screen = screens[page.component];

        return screen !== undefined && !(screen.awaits in page.props)
            ? screen.skeleton
            : null;
    });

    const failed = ref(false);

    watch(skeleton, (current) => {
        if (current === null) {
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
                if (!event.detail.visit.prefetch && skeleton.value !== null) {
                    failed.value = true;
                }
            }),
            /*
             * Back or forward onto an instant visit that was abandoned for another restores a page
             * that never got its props, and history does not ask the server. A navigation with no
             * visit behind it is exactly that case.
             */
            router.on('navigate', (event) => {
                if (
                    event.detail.visitId === undefined &&
                    skeleton.value !== null
                ) {
                    router.reload();
                }
            }),
        );
    });

    onUnmounted(() => stops.forEach((stop) => stop()));

    return { skeleton, failed, retry };
}
