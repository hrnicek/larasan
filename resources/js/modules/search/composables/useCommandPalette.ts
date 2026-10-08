import { ref } from 'vue';
import type { Ref } from 'vue';
import type { SearchKind } from '@/modules/search/types';

const open = ref(false);
const kind = ref<SearchKind | null>(null);

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
