<script setup lang="ts">
import { Check, CircleAlert, Loader2, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import type { SaveState } from '@/modules/page/composables/usePageAutosave';

const props = defineProps<{ state: SaveState }>();

const wording = computed(() => ({
    idle: null,
    pending: { label: 'Unsaved changes', icon: Loader2, tone: 'text-muted-foreground' },
    saving: { label: 'Saving…', icon: Loader2, tone: 'text-muted-foreground' },
    saved: { label: 'Saved', icon: Check, tone: 'text-muted-foreground' },
    failed: { label: 'Could not save — retrying', icon: CircleAlert, tone: 'text-destructive' },
    conflict: { label: 'This page changed elsewhere', icon: TriangleAlert, tone: 'text-destructive' },
}[props.state]));
</script>

<template>
    <p class="flex min-h-4 items-center gap-1.5 text-xs" :class="wording?.tone" aria-live="polite">
        <template v-if="wording">
            <component
                :is="wording.icon"
                class="size-3.5"
                :class="state === 'saving' && 'animate-spin'"
                aria-hidden="true"
            />
            {{ wording.label }}
        </template>
    </p>
</template>
