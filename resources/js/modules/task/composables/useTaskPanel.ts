import { router } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

/**
 * The task detail panel as an address rather than a piece of local state.
 *
 * `?task=` on whichever screen it was opened from, so a copied link reopens the same screen with
 * the same task, back closes it and forward reopens it. The visit is partial — only `taskDetail`
 * — so the board, the list or the results behind it are not re-read.
 *
 * Four screens open this panel. The rules about *closing* it are subtle enough that four copies
 * would have drifted, which is the whole reason this is a composable rather than a block repeated
 * per page.
 */
export function useTaskPanel() {
    /*
     * Those four screens import the panel asynchronously, so its chunk is not part of what a list
     * downloads to draw itself. It is fetched here once the screen has stopped working: early
     * enough that a click on a row finds it already in memory, late enough to be off the path to
     * first paint.
     */
    onMounted(() => {
        const fetchPanel = (): void => void import('@/modules/task/components/TaskDetailPanel.vue');

        if (typeof window.requestIdleCallback === 'function') {
            window.requestIdleCallback(fetchPanel);

            return;
        }

        window.setTimeout(fetchPanel, 1_000);
    });

    /*
     * Whether the panel was opened from this screen or entered by its address. Closing has to
     * answer two different questions: "go back to where I was" and "there is no back — take the
     * panel off this page". `history.back()` for the second walks out of the application
     * entirely, into whatever the person was looking at before it.
     */
    const openedFromHere = ref(false);

    const addressWithout = (): string => {
        const url = new URL(window.location.href);

        url.searchParams.delete('task');

        return `${url.pathname}${url.search}`;
    };

    const open = (taskId: string): void => {
        openedFromHere.value = true;

        const params = new URLSearchParams(window.location.search);

        params.set('task', taskId);

        /*
         * `activity` as well as `taskDetail`. It is a deferred prop, so the response carries the
         * *promise* of it rather than the region itself — but a partial reload that never names
         * it leaves the key at whatever the last screen put there, and `Deferred` reads a key
         * that exists as a region that has already arrived. The thread then stays empty however
         * much has been said on the task.
         */
        router.get(window.location.pathname, Object.fromEntries(params), {
            only: ['taskDetail', 'activity'],
            preserveState: true,
            preserveScroll: true,
        });
    };

    const close = (): void => {
        if (openedFromHere.value) {
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
