<script setup lang="ts">
import { computed } from 'vue';
import { projectIconComponent, projectIconLabel, projectIconNames } from '@/lib/projectIcon';

const props = withDefaults(
    defineProps<{
        modelValue: string | null;
        disabled?: boolean;
        size?: 'sm' | 'md';
    }>(),
    { disabled: false, size: 'sm' },
);

const emit = defineEmits<{ 'update:modelValue': [string | null] }>();

const tile = computed(() => (props.size === 'sm' ? 'size-8' : 'size-9'));
const glyph = computed(() => (props.size === 'sm' ? 'size-4' : 'size-[18px]'));
const grid = computed(() =>
    props.size === 'sm' ? 'grid max-h-56 grid-cols-7 gap-1 overflow-y-auto' : 'flex flex-wrap gap-1.5',
);
</script>

<template>
    <div>
        <div class="flex h-6 items-center justify-between">
            <h3 class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">Icon</h3>

            <button
                v-if="props.modelValue"
                type="button"
                class="rounded px-1 text-[11px] text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                :disabled="props.disabled"
                @click="emit('update:modelValue', null)"
            >
                Clear
            </button>
        </div>

        <div class="mt-2" :class="grid">
            <button
                v-for="name in projectIconNames"
                :key="name"
                type="button"
                class="flex items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                :class="[tile, props.modelValue === name ? 'bg-accent text-foreground ring-1 ring-primary-ring' : '']"
                :title="projectIconLabel(name)"
                :aria-label="projectIconLabel(name)"
                :aria-pressed="props.modelValue === name"
                :disabled="props.disabled"
                @click="emit('update:modelValue', name)"
            >
                <component :is="projectIconComponent(name)" :class="glyph" />
            </button>
        </div>

        <p class="mt-3 text-[11px] text-muted-foreground">
            Without an icon, the tile carries the project's initial.
        </p>
    </div>
</template>
