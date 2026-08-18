import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo?: Echo<'reverb'>;
    }
}

/**
 * Realtime is a collaboration layer, never the source of truth: the server stays
 * authoritative and a client that misses an event refetches rather than replaying.
 * See docs/adr/0008-realtime-architecture.md.
 *
 * Deliberately not imported from app.ts — Echo and pusher-js add roughly 70 kB to the
 * entry chunk, so the first screen that actually subscribes calls this (TASK-170-005).
 */
export function initializeEcho(): Echo<'reverb'> {
    if (window.Echo) {
        return window.Echo;
    }

    window.Pusher = Pusher;

    window.Echo = new Echo<'reverb'>({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    return window.Echo;
}
