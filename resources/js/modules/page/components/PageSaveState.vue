<script setup lang="ts">
import { Check, CircleAlert, Loader2, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import type { SaveState } from '@/modules/page/composables/usePageAutosave';

/**
 * What the person writing is told about their own words.
 *
 * `aria-live="polite"`, because the sentence changes while somebody is typing and a screen
 * reader should mention it between thoughts rather than interrupt one.
 */
const props = defineProps<{ state: SaveState }>();

const wording = computed(() => ({
    idle: { label: 'All changes saved', icon: Check, tone: 'text-muted-foreground' },
    pending: { label: 'Unsaved changes', icon: Loader2, tone: 'text-muted-foreground' },
    saving: { label: 'Saving…', icon: Loader2, tone: 'text-muted-foreground' },
    saved: { label: 'Saved', icon: Check, tone: 'text-muted-foreground' },
    failed: { label: 'Could not save — trying again on the next change', icon: CircleAlert, tone: 'text-destructive' },
    conflict: { label: 'This page changed elsewhere', icon: TriangleAlert, tone: 'text-destructive' },
}[props.state]));
</script>

<template>
    <p class="flex items-center gap-1.5 text-xs" :class="wording.tone" aria-live="polite">
        <component
            :is="wording.icon"
            class="size-3.5"
            :class="state === 'saving' && 'animate-spin'"
            aria-hidden="true"
        />
        {{ wording.label }}
    </p>
</template>
