import { router, usePage } from '@inertiajs/vue3';
import type Echo from 'laravel-echo';
import type { MaybeRefOrGetter, Ref } from 'vue';
import { onBeforeUnmount, onMounted, readonly, ref, toValue, watch } from 'vue';

export type ViewInvalidated = {
    change: string;
    subject: { type: string; id: string };
    actorId: number | null;
};

type PrivateChannel = ReturnType<Echo<'reverb'>['private']>;

/** Window in which a burst of events collapses into one refetch. */
const COALESCE_MS = 250;

export type RealtimeConnection = 'idle' | 'connecting' | 'connected' | 'offline';

const connection = ref<RealtimeConnection>('idle');

const reconnectHandlers = new Set<() => void>();

let bound = false;
let hasConnected = false;

// Echo does not type its connector's pusher-js connection.
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

        // Missed events are never replayed, so a reconnect refetches; the first connection does not.
        // See ADR-0008.
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

export function useRealtimeConnection(): Readonly<Ref<RealtimeConnection>> {
    return readonly(connection);
}

export type Reachability = 'online' | 'offline';

// Separate from the socket state: Reverb can be down while the server is reachable.
const reachability = ref<Reachability>('online');

let watchingNetwork = false;

export function useReachability(): Readonly<Ref<Reachability>> {
    return readonly(reachability);
}

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

    // `navigator.onLine` cannot see an unreachable server. Not cancelled: Inertia also fires this for
    // errors thrown while resolving a page component.
    router.on('networkError', () => {
        reachability.value = 'offline';
    });

    router.on('success', () => {
        reachability.value = 'online';
    });
}

// An event only invalidates: the region refetches instead of patching state from the payload. See ADR-0008.
export function useRealtime(options: {
    /** Without the `private-` prefix Echo adds. */
    channels: MaybeRefOrGetter<string[]>;
    /** Props to refetch; all of them when absent. */
    only?: string[];
    /** When set, a failed refetch is reported here rather than through Inertia's error page. */
    onFailure?: () => void;
}): void {
    const page = usePage();
    const { onFailure } = options;

    // A network error is not cancelled, so the shell's offline notice and reachability still see it.
    const refetch = coalesced(() =>
        router.reload({
            ...(options.only === undefined ? {} : { only: options.only }),
            ...(onFailure === undefined
                ? {}
                : {
                      onHttpException: () => {
                          onFailure();

                          return false;
                      },
                      onNetworkError: () => {
                          onFailure();
                      },
                  }),
        }),
    );

    onReconnect(refetch);

    const onInvalidated = (event: ViewInvalidated): void => {
        // The actor's own response already carried the change.
        if (event.actorId !== null && event.actorId === page.props.auth.user?.id) {
            return;
        }

        refetch();
    };

    useSubscription(options.channels, (channel) => {
        channel.listen('.view.invalidated', onInvalidated);

        return () => channel.stopListening('.view.invalidated', onInvalidated);
    });
}

export function useInboxRealtime(only: string[] = ['unreadNotifications']): void {
    const page = usePage();
    const refetch = coalesced(() => router.reload({ only }));

    onReconnect(refetch);

    useSubscription(
        () => (page.props.auth.user === null ? [] : [`user.${page.props.auth.user.id}`]),
        (channel) => {
            channel.notification(refetch);

            return () => channel.stopListeningForNotification(refetch);
        },
    );
}

function onReconnect(handler: () => void): void {
    onMounted(() => {
        reconnectHandlers.add(handler);
    });

    onBeforeUnmount(() => {
        reconnectHandlers.delete(handler);
    });
}

// Trailing-edge and not reset by later events, so a steady stream cannot postpone the refetch.
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

// Echo hands every caller the same channel object, and leaving it drops everyone's listeners.
const subscribers = new Map<string, number>();

function useSubscription(
    channels: MaybeRefOrGetter<string[]>,
    subscribe: (channel: PrivateChannel) => () => void,
): void {
    let echo: Echo<'reverb'> | null = null;
    let joined: { name: string; stop: () => void }[] = [];
    let latestJoin = 0;
    let disposed = false;

    const leave = (): void => {
        joined.forEach(({ name, stop }) => {
            stop();

            const remaining = (subscribers.get(name) ?? 1) - 1;

            if (remaining > 0) {
                subscribers.set(name, remaining);

                return;
            }

            subscribers.delete(name);
            echo?.leave(name);
        });

        joined = [];
    };

    const join = async (): Promise<void> => {
        const attempt = ++latestJoin;
        const names = [...new Set(toValue(channels).filter((name) => name.length > 0))];

        if (names.length === 0) {
            leave();

            return;
        }

        const { initializeEcho } = await import('@/echo');

        if (disposed || attempt !== latestJoin) {
            return;
        }

        echo ??= initializeEcho();
        observe(echo);
        leave();

        joined = names.map((name) => {
            subscribers.set(name, (subscribers.get(name) ?? 0) + 1);

            return { name, stop: subscribe(echo!.private(name)) };
        });
    };

    onMounted(join);
    onBeforeUnmount(() => {
        disposed = true;
        leave();
    });
    watch(() => toValue(channels).join('|'), () => void join());
}
