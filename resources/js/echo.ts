import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo?: Echo<'reverb'>;
    }
}

// Loaded lazily by the first subscribing screen to keep Echo and pusher-js (~70 kB) out of the entry chunk. See ADR-0008.
export function initializeEcho(): Echo<'reverb'> {
    if (window.Echo) {
        return window.Echo;
    }

    window.Pusher = Pusher;

    window.Echo = new Echo<'reverb'>({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT || 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT || 443),
        forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    return window.Echo;
}
