<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import { accentColorNames, accentDotClass, isCustomAccent } from '@/lib/accentColor';

/**
 * The eight-name palette, drawn as swatches, and a ninth that opens the browser's colour picker.
 *
 * Presentational, and shared on purpose: the project header's popover writes each pick
 * immediately, the settings form carries it with the rest of the fields, and a second copy of
 * the grid would be a second place for the palette to go stale.
 *
 * The eight come first and stay the default — they are tuned for both themes and can be re-tuned
 * globally, which a chosen colour cannot (ADR-0021). The ninth is for when the eight are not the
 * eight somebody wanted.
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

const isCustom = computed(() => isCustomAccent(props.modelValue));

/** What the picker opens on: the colour in hand, or a starting point rather than black. */
const customValue = computed(() => (isCustom.value ? (props.modelValue as string) : '#3f7d5a'));

/*
 * `change` rather than `input`: a colour picker fires as the cursor is dragged across the
 * spectrum, and the project header writes every pick straight to the server. One commit per
 * decision, not one per pixel.
 */
function choose(event: Event): void {
    const value = (event.target as HTMLInputElement).value.toLowerCase();

    emit('update:modelValue', value);
}
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

            <!--
                The ninth. A label rather than a button, because the control that opens the
                picker is the input itself — laid over the swatch and invisible, so the swatch
                can look like the eight beside it instead of like a browser's own colour well,
                which is a different size and shape in every browser.
            -->
            <label
                class="relative flex items-center justify-center overflow-hidden rounded-md text-white transition-transform hover:scale-110 focus-within:ring-2 focus-within:ring-primary-ring focus-within:ring-offset-2"
                :class="[swatch, { 'opacity-50': props.disabled }]"
                :style="
                    isCustom
                        ? { backgroundColor: customValue }
                        : {
                              backgroundImage:
                                  'conic-gradient(from 0deg, #ef4444, #f59e0b, #10b981, #0ea5e9, #8b5cf6, #f43f5e, #ef4444)',
                          }
                "
            >
                <span class="sr-only">Choose another colour</span>

                <Check v-if="isCustom" :class="mark" />

                <input
                    type="color"
                    class="absolute inset-0 size-full cursor-pointer opacity-0 disabled:cursor-not-allowed"
                    :value="customValue"
                    :disabled="props.disabled"
                    aria-label="Choose another colour"
                    @change="choose"
                />
            </label>
        </div>
    </div>
</template>
