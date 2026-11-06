import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { t } from '@/lib/i18n';

export function initializeOfflineNotice(): void {
    // The `networkError` event does not carry the visit, so the method is remembered from `start`.
    let writing = false;

    router.on('start', (event) => {
        writing = event.detail.visit.method.toLowerCase() !== 'get';
    });

    router.on('networkError', () => {
        toast.error(
            writing
                ? t(
                      'That change could not be saved: the server could not be reached. Nothing was lost — try again once the connection is back.',
                  )
                : t(
                      'That page could not be loaded: the server could not be reached.',
                  ),
        );
    });
}
