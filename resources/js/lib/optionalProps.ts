import { router } from '@inertiajs/vue3';

/**
 * Ask the server again for props it only sends when asked (`Inertia::optional`).
 *
 * Deferred by a tick, and that is the whole point of this function existing. Inertia interrupts
 * the in-flight request whenever a visit starts, and a visit started from inside the previous
 * one's `onSuccess` or `onFinish` is still inside that request — its own `send()` has not settled,
 * so the queue has not let go of it. The partial request goes out and is aborted before it
 * answers: in the network panel it stays `pending` for ever, and the region that asked for it
 * shows its skeleton until the page is reloaded by hand.
 *
 * A macrotask is late enough. The queue drops the finished request in a `finally` chained off
 * `send()`, which is a microtask, so a `setTimeout` of zero runs after the queue is empty.
 *
 * This is what a write inside an optional region needs: the redirect after it carries the whole
 * page **except** the optional props, so the region has to ask for its own again.
 */
export function reloadOptional(only: string[]): void {
    setTimeout(() => router.reload({ only }), 0);
}
