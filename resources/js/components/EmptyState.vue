<script setup lang="ts">
import type { Component } from 'vue';

/**
 * What a list says when it has nothing in it.
 *
 * Two rules, and they are the whole component. **Say why it is empty**, because "nothing due
 * today" and "nothing overdue" mean opposite things and a shared "No results" says neither. And
 * **offer the next action**, because a person looking at an empty screen is a person who came
 * here to do something — a sentence that only reports the absence makes them go and find the
 * control themselves.
 *
 * `compact` is for a place inside a list — a section with no rows, a board column — where a full
 * panel would be larger than the thing containing it.
 */
withDefaults(
    defineProps<{
        title: string;
        description?: string;
        icon?: Component;
        compact?: boolean;
    }>(),
    { compact: false },
);
</script>

<template>
    <div
        class="flex flex-col items-center justify-center gap-2 text-center"
        :class="compact ? 'px-4 py-6' : 'rounded-lg border border-dashed border-border px-6 py-12'"
    >
        <component :is="icon" v-if="icon" class="size-6 text-muted-foreground/60" aria-hidden="true" />

        <p class="text-sm" :class="compact ? 'text-muted-foreground' : 'font-medium text-foreground'">{{ title }}</p>
        <p v-if="description" class="max-w-sm text-sm text-muted-foreground">{{ description }}</p>

        <div v-if="$slots.action" class="mt-1">
            <slot name="action" />
        </div>
    </div>
</template>
