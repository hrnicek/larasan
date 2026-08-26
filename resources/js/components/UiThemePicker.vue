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
                  The scheme in miniature: rail, canvas, a card and the accent. Drawn inside its
                  own `data-theme` so the swatch reads the theme's real tokens rather than a second
                  copy of the palette that would drift the first time one was re-tuned — and the
                  accent is in it precisely because it is the one thing that does not change.
                -->
                <span
                    :data-theme="theme.value"
                    class="flex h-14 w-24 shrink-0 overflow-hidden rounded-sm border border-border bg-background"
                    aria-hidden="true"
                >
                    <span
                        class="flex h-full w-6 flex-col items-center gap-1 bg-chrome pt-1.5"
                    >
                        <span
                            class="h-1.5 w-3.5 rounded-xs bg-chrome-primary"
                        />
                        <span class="h-1 w-3.5 rounded-xs bg-chrome-border" />
                        <span class="h-1 w-3.5 rounded-xs bg-chrome-border" />
                    </span>
                    <span class="flex flex-1 flex-col justify-end gap-1 p-1.5">
                        <span
                            class="h-1 w-2/3 rounded-xs bg-muted-foreground/60"
                        />
                        <span
                            class="flex h-4 w-full items-center rounded-xs border border-border bg-card px-1"
                        >
                            <span class="h-1.5 w-1.5 rounded-full bg-primary" />
                        </span>
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
