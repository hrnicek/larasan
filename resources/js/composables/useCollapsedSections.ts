import { ref, watch  } from 'vue';
import type {Ref} from 'vue';

const storageKey = (projectId: string) => `collapsed-sections:${projectId}`;

function read(projectId: string): string[] {
    if (typeof window === 'undefined') {
        return [];
    }

    try {
        const stored = window.localStorage.getItem(storageKey(projectId));
        const parsed: unknown = stored === null ? [] : JSON.parse(stored);

        return Array.isArray(parsed) ? parsed.filter((id): id is string => typeof id === 'string') : [];
    } catch {
        // A corrupt entry is a collapsed column, not a broken page.
        return [];
    }
}

/**
 * Which columns this person has collapsed, per project.
 *
 * Local, never sent to the server: it is how one person is reading a board right now, not
 * something about the project, and syncing it would make one person's view everybody's.
 */
export function useCollapsedSections(projectId: string): {
    collapsed: Ref<string[]>;
    isCollapsed: (sectionId: string | null) => boolean;
    toggle: (sectionId: string | null) => void;
} {
    const collapsed = ref<string[]>(read(projectId));

    watch(collapsed, (value) => {
        if (typeof window !== 'undefined') {
            window.localStorage.setItem(storageKey(projectId), JSON.stringify(value));
        }
    }, { deep: true });

    // The ungrouped bucket has no id of its own and still collapses like any other column.
    const key = (sectionId: string | null) => sectionId ?? 'ungrouped';

    return {
        collapsed,
        isCollapsed: (sectionId) => collapsed.value.includes(key(sectionId)),
        toggle: (sectionId) => {
            const id = key(sectionId);

            collapsed.value = collapsed.value.includes(id)
                ? collapsed.value.filter((current) => current !== id)
                : [...collapsed.value, id];
        },
    };
}
