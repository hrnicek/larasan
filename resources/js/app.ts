import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import { initializeReachability } from '@/composables/useRealtime';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { initializeOfflineNotice } from '@/lib/offlineNotice';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            // The error page and the marketing page both stand on their own: an error is often
            // an answer to somebody who is not signed in, and the shell would have nothing to
            // put in its sidebar.
            case name === 'Welcome':
            case name === 'Error':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();

// This will watch whether the server can be reached, so the shell can say when it cannot...
initializeReachability();
initializeOfflineNotice();

/*
 * The service worker caches the app shell — hashed build assets and fonts, never a page (see
 * `public/sw.js`). It is registered in production only: in development a cached asset is a
 * debugging session nobody enjoys, and Vite is already serving from memory.
 */
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        void navigator.serviceWorker.register('/sw.js');
    });
}
