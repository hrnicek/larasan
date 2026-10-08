import { router } from '@inertiajs/vue3';

// Deferred a tick: Inertia aborts a reload started inside the previous visit's `onSuccess`/`onFinish`.
export function reloadOptional(only: string[]): void {
    setTimeout(() => router.reload({ only }), 0);
}
