import { usePage } from '@inertiajs/vue3';
import { computed, inject, provide, ref    } from 'vue';
import type {ComputedRef, InjectionKey, Ref} from 'vue';

const COOKIE = 'sidebar_state';
const ONE_YEAR = 60 * 60 * 24 * 365;

type Shell = {
    collapsed: ComputedRef<boolean>;
    mobileOpen: Ref<boolean>;
    toggle: () => void;
    openMobile: () => void;
    closeMobile: () => void;
};

const ShellKey: InjectionKey<Shell> = Symbol('shell');

/**
 * Whether *this* part of the tree draws itself collapsed.
 *
 * Separate from the shell's own state because collapsing is a property of the rail, not of the
 * application: the same sidebar rendered inside the mobile drawer has 288px to work with and must
 * always be expanded, whatever the rail on a desktop was left as.
 */
const CollapsedKey: InjectionKey<ComputedRef<boolean>> = Symbol('shell-collapsed');

export function provideCollapsed(collapsed: ComputedRef<boolean>): void {
    provide(CollapsedKey, collapsed);
}

export function useCollapsed(): ComputedRef<boolean> {
    const collapsed = inject(CollapsedKey, null);

    if (collapsed === null) {
        throw new Error('useCollapsed() was called outside AppShell, which is the component that provides it.');
    }

    return collapsed;
}

/**
 * The shell's own state: whether the sidebar is collapsed to icons, and whether the mobile
 * drawer is open.
 *
 * Provided once by `AppShell` and injected by everything under it, **not** held at module scope.
 * This application is server-side rendered, and a module-scope `ref` in an SSR bundle belongs to
 * the Node process rather than to the request: the first render would decide the collapsed state
 * for every visitor afterwards, and the browser would then correct it into a hydration mismatch.
 * That is not a theoretical risk — it is what the first version of this file did, and the
 * mismatch warning named the exact class it disagreed about.
 *
 * The collapsed state is a cookie rather than local storage, so the server can read it and ship
 * `sidebarOpen`, and the sidebar renders in the right shape before any JavaScript runs.
 * `bootstrap/app.php` leaves this cookie unencrypted for that reason.
 */
export function provideShell(): Shell {
    const page = usePage();

    const collapsed = ref(!page.props.sidebarOpen);
    const mobileOpen = ref(false);

    function setCollapsed(value: boolean): void {
        collapsed.value = value;
        document.cookie = `${COOKIE}=${!value}; path=/; max-age=${ONE_YEAR}; SameSite=Lax`;
    }

    const shell: Shell = {
        collapsed: computed(() => collapsed.value),
        mobileOpen,
        toggle: () => setCollapsed(!collapsed.value),
        openMobile: () => (mobileOpen.value = true),
        closeMobile: () => (mobileOpen.value = false),
    };

    provide(ShellKey, shell);
    provideCollapsed(shell.collapsed);

    return shell;
}

export function useShell(): Shell {
    const shell = inject(ShellKey, null);

    if (shell === null) {
        throw new Error('useShell() was called outside AppShell, which is the component that provides it.');
    }

    return shell;
}
