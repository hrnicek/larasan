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
    // Read from page props rather than a VITE_ variable so renaming APP_NAME needs no rebuild.
    title: (title, page) => (title ? `${title} — ${page.props.name}` : String(page.props.name)),
    withApp: applyModalLayer,
    layout: (name) => {
        switch (true) {
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

// Caches hashed build assets and fonts only (see public/sw.js); skipped in development to avoid stale assets.
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        void navigator.serviceWorker.register('/sw.js');
    });
}
