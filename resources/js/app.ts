import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import { initializeReachability } from '@/composables/useRealtime';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { applyModalLayer, configureModalLayer } from '@/lib/modalLayer';
import { initializeOfflineNotice } from '@/lib/offlineNotice';

configureModalLayer();

createInertiaApp({
    /*
     * The name comes from the page rather than from a build-time environment variable, so
     * `APP_NAME` is the only place it is written and renaming the product does not need a
     * rebuild to reach the browser tab.
     */
    title: (title, page) => (title ? `${title} — ${page.props.name}` : String(page.props.name)),
    withApp: applyModalLayer,
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

initializeTheme();

initializeFlashToast();

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
