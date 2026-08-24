import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

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

        router.get(window.location.pathname, Object.fromEntries(params), {
            only: ['taskDetail'],
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
