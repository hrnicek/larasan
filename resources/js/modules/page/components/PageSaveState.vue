<script setup lang="ts">
import { Check, CircleAlert, Loader2, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { t } from '@/lib/i18n';
import type { SaveState } from '@/modules/page/composables/usePageAutosave';

const props = defineProps<{ state: SaveState }>();

const wording = computed(
    () =>
        ({
            idle: null,
            pending: {
                label: t('Unsaved changes'),
                icon: Loader2,
                tone: 'text-muted-foreground',
            },
            saving: {
                label: t('Saving…'),
                icon: Loader2,
                tone: 'text-muted-foreground',
            },
            saved: {
                label: t('Saved'),
                icon: Check,
                tone: 'text-muted-foreground',
            },
            failed: {
                label: t('Could not save — retrying'),
                icon: CircleAlert,
                tone: 'text-destructive',
            },
            conflict: {
                label: t('This page changed elsewhere'),
                icon: TriangleAlert,
                tone: 'text-destructive',
            },
        })[props.state],
);
</script>

<template>
    <p
        class="flex min-h-4 items-center gap-1.5 text-xs"
        :class="wording?.tone"
        aria-live="polite"
    >
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
