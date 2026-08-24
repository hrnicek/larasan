import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const COOKIE = 'sidebar_state';
const ONE_YEAR = 60 * 60 * 24 * 365;

/**
 * The shell's own state: whether the sidebar is collapsed to icons, and whether the mobile
 * drawer is open.
 *
 * Module scope rather than per-component, because the topbar's toggle and the sidebar it toggles
 * are siblings — and because the shell is mounted once for the life of the tab.
 *
 * The collapsed state is a cookie, not local storage: the server reads it on the first request
 * and ships it as `sidebarOpen`, so the sidebar renders in the right shape before any JavaScript
 * runs. `bootstrap/app.php` leaves this cookie unencrypted for the same reason.
 */
const collapsed = ref<boolean | null>(null);
const mobileOpen = ref(false);

export function useShell() {
    const page = usePage();

    if (collapsed.value === null) {
        collapsed.value = !page.props.sidebarOpen;
    }

    function setCollapsed(value: boolean): void {
        collapsed.value = value;
        document.cookie = `${COOKIE}=${!value}; path=/; max-age=${ONE_YEAR}; SameSite=Lax`;
    }

    return {
        collapsed: computed(() => collapsed.value === true),
        mobileOpen,
        toggle: () => setCollapsed(collapsed.value !== true),
        openMobile: () => (mobileOpen.value = true),
        closeMobile: () => (mobileOpen.value = false),
    };
}
