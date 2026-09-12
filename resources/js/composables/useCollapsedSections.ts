import { ref, watch } from 'vue';

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
        return [];
    }
}

export function useCollapsedSections(projectId: string): {
    isCollapsed: (sectionId: string | null) => boolean;
    toggle: (sectionId: string | null) => void;
} {
    const collapsed = ref<string[]>(read(projectId));

    watch(collapsed, (value) => {
        if (typeof window !== 'undefined') {
            window.localStorage.setItem(storageKey(projectId), JSON.stringify(value));
        }
    }, { deep: true });

    const key = (sectionId: string | null) => sectionId ?? 'ungrouped';

    return {
        isCollapsed: (sectionId) => collapsed.value.includes(key(sectionId)),
        toggle: (sectionId) => {
            const id = key(sectionId);

            collapsed.value = collapsed.value.includes(id)
                ? collapsed.value.filter((current) => current !== id)
                : [...collapsed.value, id];
        },
    };
}
