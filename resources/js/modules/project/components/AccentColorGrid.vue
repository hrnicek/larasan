<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import { accentColorNames, accentDotClass } from '@/lib/accentColor';

/**
 * The eight-name palette, drawn as swatches.
 *
 * Presentational, and shared on purpose: the project header's popover writes each pick
 * immediately, the settings form carries it with the rest of the fields, and a second copy of
 * the grid would be a second place for the palette to go stale.
 */
const props = withDefaults(
    defineProps<{
        modelValue: string | null;
        disabled?: boolean;
        size?: 'sm' | 'md';
    }>(),
    { disabled: false, size: 'sm' },
);

const emit = defineEmits<{ 'update:modelValue': [string | null] }>();

const swatch = computed(() => (props.size === 'sm' ? 'size-6' : 'size-8'));
const mark = computed(() => (props.size === 'sm' ? 'size-3.5' : 'size-4'));
</script>

<template>
    <div>
        <div class="flex h-6 items-center justify-between">
            <h3 class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">Colour</h3>

            <!-- Clearing is its own control rather than a swatch: an empty square in a row of
                 colours reads as one more colour, and a neutral one reads as slate. -->
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

        <div class="mt-2 flex flex-wrap gap-1.5">
            <button
                v-for="color in accentColorNames"
                :key="color"
                type="button"
                class="flex items-center justify-center rounded-md text-white transition-transform hover:scale-110 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                :class="[swatch, accentDotClass(color)]"
                :aria-label="color"
                :aria-pressed="props.modelValue === color"
                :disabled="props.disabled"
                @click="emit('update:modelValue', color)"
            >
                <Check v-if="props.modelValue === color" :class="mark" />
            </button>
        </div>
    </div>
</template>
