<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { update } from '@/routes/appearance';
import type { UiTheme, UiThemeOption } from '@/types/ui';

const { current, themes } = defineProps<{
    current: UiTheme;
    themes: UiThemeOption[];
}>();

const form = useForm({ ui_theme: current });

/**
 * The attribute the root template writes is what every token block selects on, so setting it
 * here repaints the page at once and the radio is a preview as well as a choice. A reload
 * without a save puts the stored value back, which is the behaviour a preview should have.
 */
function preview(theme: UiTheme): void {
    form.ui_theme = theme;
    document.documentElement.dataset.theme = theme;
}

function submit(): void {
    form.put(update.url(), { preserveScroll: true });
}
</script>

<template>
    <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="flex flex-col gap-2">
            <label
                v-for="theme in themes"
                :key="theme.value"
                class="flex cursor-pointer items-center gap-4 rounded-md border p-4 transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary-ring"
                :class="
                    form.ui_theme === theme.value
                        ? 'border-primary bg-primary-subtle'
                        : 'border-border hover:bg-muted'
                "
            >
                <input
                    class="sr-only"
                    type="radio"
                    :value="theme.value"
                    :checked="form.ui_theme === theme.value"
                    @change="preview(theme.value)"
                />

                <!--
                  The scheme in miniature: chrome, canvas, card. It is drawn inside its own
                  `data-theme` so the swatch reads the theme's real tokens instead of carrying a
                  second copy of the palette that would drift the first time one was re-tuned.
                -->
                <span
                    :data-theme="theme.value"
                    class="flex h-12 w-20 shrink-0 overflow-hidden rounded-sm border border-border bg-background"
                    aria-hidden="true"
                >
                    <span class="h-full w-5 bg-chrome" />
                    <span class="flex flex-1 items-end p-1.5">
                        <span
                            class="h-4 w-full rounded-xs border border-border bg-card"
                        />
                    </span>
                </span>

                <span class="flex-1">
                    <span class="block text-sm font-medium">{{
                        theme.label
                    }}</span>
                    <span class="mt-0.5 block text-xs text-muted-foreground">{{
                        theme.description
                    }}</span>
                </span>

                <Check
                    v-if="form.ui_theme === theme.value"
                    class="h-4 w-4 shrink-0 text-primary"
                    aria-hidden="true"
                />
            </label>
        </div>

        <div>
            <button
                type="submit"
                class="rounded-md bg-primary px-3.5 py-1.5 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary-hover active:bg-primary-active disabled:opacity-60"
                :disabled="form.processing || form.ui_theme === current"
            >
                Save theme
            </button>
        </div>
    </form>
</template>
