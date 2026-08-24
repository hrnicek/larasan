import { router, usePage } from '@inertiajs/vue3';
import type Echo from 'laravel-echo';
import type { MaybeRefOrGetter, Ref } from 'vue';
import { onBeforeUnmount, onMounted, readonly, ref, toValue, watch } from 'vue';

/** The payload every shared-channel broadcast carries (`ViewInvalidated`). */
export type ViewInvalidated = {
    change: string;
    subject: { type: string; id: string };
    actorId: number | null;
};

type PrivateChannel = ReturnType<Echo<'reverb'>['private']>;

/**
 * How long a region waits before refetching, so a burst of events becomes one request.
 *
 * A drag across a board is several placements in a second and a rename is one event per
 * keystroke-batch; each of those is a single change to a reader, and answering each with its own
 * round trip is how a busy board becomes a slow one. A quarter of a second is below what anybody
 * reads as a delay and above the length of a burst.
 */
const COALESCE_MS = 250;

/** What the socket is doing, for a screen that wants to say so quietly. */
export type RealtimeConnection = 'idle' | 'connecting' | 'connected' | 'offline';

/**
 * The connection is one thing for the whole page — `initializeEcho()` returns a single client —
 * so its state lives beside it rather than per subscription.
 */
const connection = ref<RealtimeConnection>('idle');

/** Called when the socket comes back, so each subscribed region can refetch. */
const reconnectHandlers = new Set<() => void>();

let bound = false;
let hasConnected = false;

/**
 * pusher-js's connection object, narrowed to what is used here. Echo does not type its
 * connector's transport, and a cast of three members is more honest than an `any` that would
 * accept anything at all.
 */
type SocketConnection = {
    state: string;
    bind(event: string, handler: (payload: { current: string; previous: string }) => void): void;
};

const socketOf = (echo: Echo<'reverb'>): SocketConnection | null => {
    const connector = echo.connector as unknown as { pusher?: { connection?: SocketConnection } };

    return connector.pusher?.connection ?? null;
};

const observe = (echo: Echo<'reverb'>): void => {
    if (bound) {
        return;
    }

    const socket = socketOf(echo);

    if (socket === null) {
        return;
    }

    bound = true;
    connection.value = describe(socket.state);
    hasConnected = socket.state === 'connected';

    socket.bind('state_change', ({ current }): void => {
        connection.value = describe(current);

        if (current !== 'connected') {
            return;
        }

        /*
         * Missed events are never replayed (ADR-0008), so coming back means asking the server
         * again — but only coming *back*. The first connection of a page would otherwise refetch
         * what the page has just rendered, which is a request nobody needed.
         */
        if (hasConnected) {
            reconnectHandlers.forEach((handler) => handler());
        }

        hasConnected = true;
    });
};

const describe = (state: string): RealtimeConnection => {
    switch (state) {
        case 'connected':
            return 'connected';
        case 'initialized':
        case 'connecting':
            return 'connecting';
        default:
            return 'offline';
    }
};

/**
 * What the socket is doing. Nothing is gated on it — realtime is an enhancement, and every
 * screen works by asking the server, which is what it does without a socket too.
 */
export function useRealtimeConnection(): Readonly<Ref<RealtimeConnection>> {
    return readonly(connection);
}

/** Whether the server can be reached at all — a different question from what the socket is doing. */
export type Reachability = 'online' | 'offline';

/*
 * The socket being down and the network being gone are not the same fact and must not be shown as
 * one: Reverb can be stopped on a machine whose network is perfect, and every screen still works
 * because every screen works by asking the server. So this is a second *signal* — but it lives
 * here, beside the socket, because there is only one rule about coming back and it should not be
 * written twice.
 */
const reachability = ref<Reachability>('online');

let watchingNetwork = false;

/**
 * Whether the network is reachable, for the shell to say so.
 *
 * Two signals, because neither alone is the truth. `navigator.onLine` is false only when the
 * device knows it has no network, which it reports instantly and never wrongly — but it is true on
 * a wifi that reaches a router and nothing beyond it. Inertia's `networkError` is the opposite: it
 * fires only after a request has actually failed, which is late but certain.
 */
export function useReachability(): Readonly<Ref<Reachability>> {
    return readonly(reachability);
}

/**
 * Bound once for the application, from `app.ts`.
 *
 * Recovery is a full reload rather than the partial reloads a socket reconnect performs. A socket
 * reconnect knows it missed events on channels a region subscribed to; a network return knows
 * nothing at all about how long it was away or what changed, and the honest answer to that is to
 * ask for the page again.
 */
export function initializeReachability(): void {
    if (watchingNetwork || typeof window === 'undefined') {
        return;
    }

    watchingNetwork = true;
    reachability.value = navigator.onLine ? 'online' : 'offline';

    window.addEventListener('offline', () => {
        reachability.value = 'offline';
    });

    window.addEventListener('online', () => {
        if (reachability.value === 'online') {
            return;
        }

        reachability.value = 'online';
        router.reload();
    });

    /*
     * A request that failed is better evidence than `navigator.onLine`, which cannot see a
     * server that is simply unreachable. The event is not cancelled: Inertia also fires it for
     * errors thrown while resolving a page component, and swallowing those would hide a bug
     * behind a message about the network.
     */
    router.on('networkError', () => {
        reachability.value = 'offline';
    });

    // Anything that arrives proves the way back is open, whatever `navigator.onLine` believes.
    router.on('success', () => {
        reachability.value = 'online';
    });
}

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
    const refetch = coalesced(() => router.reload(options.only === undefined ? {} : { only: options.only }));

    onReconnect(refetch);

    useSubscription(options.channels, (channel) => {
        channel.listen('.view.invalidated', (event: ViewInvalidated) => {
            // Somebody's own change was already answered by the response to their request.
            if (event.actorId !== null && event.actorId === page.props.auth.user?.id) {
                return;
            }

            refetch();
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
    const refetch = coalesced(() => router.reload({ only }));

    onReconnect(refetch);

    useSubscription(
        () => (page.props.auth.user === null ? [] : [`user.${page.props.auth.user.id}`]),
        (channel) => channel.notification(refetch),
    );
}

/**
 * Refetch when the socket comes back, and stop when the screen goes away.
 *
 * The handler is the region's own coalesced refetch, so several subscribed regions coming back
 * at once is still one request each rather than one per event they missed — and a reconnect
 * cannot stampede.
 */
function onReconnect(handler: () => void): void {
    onMounted(() => {
        reconnectHandlers.add(handler);
    });

    onBeforeUnmount(() => {
        reconnectHandlers.delete(handler);
    });
}

/**
 * One refetch per burst.
 *
 * A duplicate event and an event that arrives out of order both need no handling of their own
 * here: this client never patches its own state from a payload, so every delivery leads to the
 * same place — a request whose answer is whatever the server holds *now*. What repetition does
 * cost is requests, and this is what stops a board from making one per card in a drag.
 *
 * Deliberately trailing-edge and not resettable: an event that lands while a refetch is already
 * scheduled is already accounted for, and restarting the timer for it would let a steady stream
 * of changes postpone the answer indefinitely.
 */
function coalesced(refetch: () => void): () => void {
    let scheduled: ReturnType<typeof setTimeout> | null = null;

    onBeforeUnmount(() => {
        if (scheduled !== null) {
            clearTimeout(scheduled);
            scheduled = null;
        }
    });

    return (): void => {
        if (scheduled !== null) {
            return;
        }

        scheduled = setTimeout(() => {
            scheduled = null;
            refetch();
        }, COALESCE_MS);
    };
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
        observe(echo);
        leave();

        names.forEach((name) => subscribe(echo!.private(name)));

        joined = names;
    };

    onMounted(join);
    onBeforeUnmount(leave);
    watch(() => toValue(channels).join('|'), () => void join());
}
