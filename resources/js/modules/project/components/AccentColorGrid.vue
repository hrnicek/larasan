<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import { accentColorNames, accentDotClass, isCustomAccent } from '@/lib/accentColor';

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

const customValue = computed(() => (isCustom.value ? (props.modelValue as string) : '#3f7d5a'));

// `change`, not `input`: the header persists each pick, so commit once rather than while dragging.
function choose(event: Event): void {
    const value = (event.target as HTMLInputElement).value.toLowerCase();

    emit('update:modelValue', value);
}
</script>

<template>
    <div>
        <div class="flex h-6 items-center justify-between">
            <h3 class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">Colour</h3>

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
