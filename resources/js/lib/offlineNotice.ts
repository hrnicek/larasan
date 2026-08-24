import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

/**
 * A request that reached nobody says so.
 *
 * The banner in the shell reports the state; this reports the *event*, because a change somebody
 * pressed a button to make must not fail as a silent nothing. Inertia's `networkError` detail
 * carries the error and not the visit, so the method is remembered from `start` — which is enough,
 * since a visit that fails is the visit that most recently began.
 */
export function initializeOfflineNotice(): void {
    let writing = false;

    router.on('start', (event) => {
        writing = event.detail.visit.method.toLowerCase() !== 'get';
    });

    router.on('networkError', () => {
        toast.error(
            writing
                ? 'That change could not be saved: the server could not be reached. Nothing was lost — try again once the connection is back.'
                : 'That page could not be loaded: the server could not be reached.',
        );
    });
}
