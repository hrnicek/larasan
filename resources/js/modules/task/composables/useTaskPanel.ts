import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';

export function useTaskPanel() {
    // Preload the async panel chunk once the page is idle, off the first-paint path.
    onMounted(() => {
        const fetchPanel = (): void =>
            void import('@/modules/task/components/TaskDetailPanel.vue');

        if (typeof window.requestIdleCallback === 'function') {
            window.requestIdleCallback(fetchPanel);

            return;
        }

        window.setTimeout(fetchPanel, 1_000);
    });

    // A panel entered by URL has no entry to go back to; `history.back()` would leave the app.
    const openedFromHere = ref(false);

    // History entries restore cached props, so a write in the panel reloads the page on close.
    const changed = ref(false);

    onUnmounted(
        router.on('finish', (event): void => {
            const visit = event.detail.visit;

            if (visit.completed && visit.method !== 'get') {
                changed.value = true;
            }
        }),
    );

    const addressWithout = (): string => {
        const url = new URL(window.location.href);

        url.searchParams.delete('task');

        return `${url.pathname}${url.search}`;
    };

    const open = (taskId: string): void => {
        openedFromHere.value = true;
        changed.value = false;

        const params = new URLSearchParams(window.location.search);

        params.set('task', taskId);

        // Name the deferred `activity` prop too, or `Deferred` treats the stale key as already loaded.
        router.get(window.location.pathname, Object.fromEntries(params), {
            only: ['taskDetail', 'activity'],
            preserveState: true,
            preserveScroll: true,
        });
    };

    const close = (): void => {
        if (openedFromHere.value) {
            if (changed.value) {
                changed.value = false;

                // Reload after popstate lands; until then the URL still carries `?task=`.
                router.once('navigate', () => router.reload());
            }

            window.history.back();

            return;
        }

        router.get(
            addressWithout(),
            {},
            {
                only: ['taskDetail'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    return { open, close };
}
