import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

export function initializeOfflineNotice(): void {
    // The `networkError` event does not carry the visit, so the method is remembered from `start`.
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
