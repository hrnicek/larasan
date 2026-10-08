import { usePage } from '@inertiajs/vue3';
import { computed, inject, provide, ref } from 'vue';
import type { ComputedRef, InjectionKey, Ref } from 'vue';

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

// Separate from the shell state: the same sidebar inside the mobile drawer is always expanded.
const CollapsedKey: InjectionKey<ComputedRef<boolean>> =
    Symbol('shell-collapsed');

export function provideCollapsed(collapsed: ComputedRef<boolean>): void {
    provide(CollapsedKey, collapsed);
}

export function useCollapsed(): ComputedRef<boolean> {
    const collapsed = inject(CollapsedKey, null);

    if (collapsed === null) {
        throw new Error(
            'useCollapsed() was called outside AppShell, which is the component that provides it.',
        );
    }

    return collapsed;
}

// Provided rather than held at module scope because it is seeded from a request-scoped prop. The
// cookie is read by the server and left unencrypted in `bootstrap/app.php`.
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
        throw new Error(
            'useShell() was called outside AppShell, which is the component that provides it.',
        );
    }

    return shell;
}
