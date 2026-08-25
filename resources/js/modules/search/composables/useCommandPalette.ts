import { ref } from 'vue';
import type { Ref } from 'vue';
import type { SearchKind } from '@/modules/search/types';

/**
 * Whether the palette is open, and the kind it opened on.
 *
 * Module state rather than provide/inject: the palette is drawn once in the shell and opened from
 * the topbar, from a keystroke anywhere, and eventually from a screen's own empty state. A
 * provider would make every one of those a component that has to be inside it.
 */
const open = ref(false);
const kind = ref<SearchKind | null>(null);

/** Fields a keystroke means something else in. */
function typingSomewhereElse(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    return (
        target.isContentEditable ||
        ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)
    );
}

export function useCommandPalette(): {
    open: Ref<boolean>;
    kind: Ref<SearchKind | null>;
    show: (on?: SearchKind | null) => void;
    hide: () => void;
    handleShortcut: (event: KeyboardEvent) => void;
} {
    function show(on: SearchKind | null = null): void {
        kind.value = on;
        open.value = true;
    }

    function hide(): void {
        open.value = false;
    }

    /**
     * `⌘K` on a Mac, `Ctrl+K` everywhere else — and neither while somebody is typing into a
     * field, where the browser and the editor both already mean something by it.
     */
    function handleShortcut(event: KeyboardEvent): void {
        if (event.key !== 'k' && event.key !== 'K') {
            return;
        }

        if (!event.metaKey && !event.ctrlKey) {
            return;
        }

        if (!open.value && typingSomewhereElse(event.target)) {
            return;
        }

        event.preventDefault();

        if (open.value) {
            hide();

            return;
        }

        show();
    }

    return { open, kind, show, hide, handleShortcut };
}
