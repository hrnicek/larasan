import { router, usePage } from '@inertiajs/vue3';
import type Echo from 'laravel-echo';
import type { MaybeRefOrGetter } from 'vue';
import { onBeforeUnmount, onMounted, toValue, watch } from 'vue';

/** The payload every shared-channel broadcast carries (`ViewInvalidated`). */
export type ViewInvalidated = {
    change: string;
    subject: { type: string; id: string };
    actorId: number | null;
};

type PrivateChannel = ReturnType<Echo<'reverb'>['private']>;

/**
 * Realtime is collaboration transport, never the source of truth (ADR-0008). An event says
 * that something changed, so the client refetches the affected region and lets the server
 * answer — a client that patched its own state from a payload would be a second
 * implementation of every rule the server already has, and would be wrong the moment it
 * missed one event.
 *
 * Echo is imported dynamically. It and pusher-js are roughly 70 kB, and somebody reading a
 * settings page should not download a websocket client to do it.
 */
export function useRealtime(options: {
    /** Channel names without the `private-` prefix Echo adds. */
    channels: MaybeRefOrGetter<string[]>;
    /** The props to refetch when something on those channels changes; all of them if absent. */
    only?: string[];
}): void {
    const page = usePage();

    useSubscription(options.channels, (channel) => {
        channel.listen('.view.invalidated', (event: ViewInvalidated) => {
            // Somebody's own change was already answered by the response to their request.
            if (event.actorId !== null && event.actorId === page.props.auth.user?.id) {
                return;
            }

            router.reload(options.only === undefined ? {} : { only: options.only });
        });
    });
}

/**
 * The shell's unread badge, told rather than asked.
 *
 * The payload carries the count and this refetches it anyway: the badge is a shared prop, so
 * one partial reload keeps it consistent with the database rather than with a message that
 * may have been missed — which is the same rule the shared channels follow.
 */
export function useInboxRealtime(only: string[] = ['unreadNotifications']): void {
    const page = usePage();

    useSubscription(
        () => (page.props.auth.user === null ? [] : [`user.${page.props.auth.user.id}`]),
        (channel) => channel.notification(() => router.reload({ only })),
    );
}

/**
 * Join while mounted, leave on unmount, and move when the channels change — the shell
 * outlives a page, so a workspace or project change must not leave a subscription listening
 * to what the person was looking at before.
 */
function useSubscription(
    channels: MaybeRefOrGetter<string[]>,
    subscribe: (channel: PrivateChannel) => void,
): void {
    let echo: Echo<'reverb'> | null = null;
    let joined: string[] = [];

    const leave = (): void => {
        joined.forEach((channel) => echo?.leave(channel));
        joined = [];
    };

    const join = async (): Promise<void> => {
        const names = toValue(channels).filter((name) => name.length > 0);

        if (names.length === 0) {
            leave();

            return;
        }

        const { initializeEcho } = await import('@/echo');

        echo ??= initializeEcho();
        leave();

        names.forEach((name) => subscribe(echo!.private(name)));

        joined = names;
    };

    onMounted(join);
    onBeforeUnmount(leave);
    watch(() => toValue(channels).join('|'), () => void join());
}
