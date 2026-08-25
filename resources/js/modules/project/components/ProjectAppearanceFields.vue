<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { ref } from 'vue';
import ProjectAppearanceController from '@/actions/App/Http/Controllers/Project/ProjectAppearanceController';
import { accentColorNames, accentDotClass } from '@/lib/accentColor';
import { projectIconComponent, projectIconLabel, projectIconNames } from '@/lib/projectIcon';

/**
 * The palette and the icon library, and what happens when one of them is clicked.
 *
 * Its own component because two surfaces offer the same two choices — the tile in the project
 * header opens it in a popover, the sidebar row opens it in a submenu of its context menu — and
 * a second copy of the grids would be a second place for the library to go stale.
 *
 * It keeps no draft: each pick is sent, and the tile re-renders from the props that come back.
 * Optimism belongs to board cards, where a rollback is a card sliding back to where it was; here
 * a failed write would leave the project claiming a colour it does not have.
 */
const props = defineProps<{
    project: { id: string; name: string; color: string | null; icon: string | null };
}>();

const saving = ref(false);

function save(color: string | null, icon: string | null): void {
    saving.value = true;

    router.put(
        ProjectAppearanceController.update.url(props.project.id),
        { color, icon },
        {
            preserveScroll: true,
            // The control stays open: picking a colour and then an icon is one errand, and one
            // that closes itself after each pick makes it two.
            preserveState: true,
            onFinish: () => (saving.value = false),
        },
    );
}
</script>

<template>
    <div>
        <section>
            <div class="flex h-6 items-center justify-between">
                <h3 class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">Colour</h3>

                <!-- Clearing is its own control rather than a swatch: an empty square in a row of
                     colours reads as one more colour, and a neutral one reads as slate. -->
                <button
                    v-if="props.project.color"
                    type="button"
                    class="rounded px-1 text-[11px] text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    :disabled="saving"
                    @click="save(null, props.project.icon)"
                >
                    Clear
                </button>
            </div>

            <div class="mt-2 grid grid-cols-8 gap-1.5">
                <button
                    v-for="color in accentColorNames"
                    :key="color"
                    type="button"
                    class="flex size-6 items-center justify-center rounded-md text-white transition-transform hover:scale-110 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                    :class="accentDotClass(color)"
                    :aria-label="color"
                    :aria-pressed="props.project.color === color"
                    :disabled="saving"
                    @click="save(color, props.project.icon)"
                >
                    <Check v-if="props.project.color === color" class="size-3.5" />
                </button>
            </div>
        </section>

        <section class="mt-4">
            <div class="flex h-6 items-center justify-between">
                <h3 class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">Icon</h3>

                <button
                    v-if="props.project.icon"
                    type="button"
                    class="rounded px-1 text-[11px] text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    :disabled="saving"
                    @click="save(props.project.color, null)"
                >
                    Clear
                </button>
            </div>

            <!-- Bounded and scrollable: the library is four rows today and neither the popover nor
                 the submenu should grow past the thing it hangs off when it is not. -->
            <div class="mt-2 grid max-h-56 grid-cols-7 gap-1 overflow-y-auto">
                <button
                    v-for="icon in projectIconNames"
                    :key="icon"
                    type="button"
                    class="flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    :class="props.project.icon === icon ? 'bg-accent text-foreground ring-1 ring-primary-ring' : ''"
                    :title="projectIconLabel(icon)"
                    :aria-label="projectIconLabel(icon)"
                    :aria-pressed="props.project.icon === icon"
                    :disabled="saving"
                    @click="save(props.project.color, icon)"
                >
                    <component :is="projectIconComponent(icon)" class="size-4" />
                </button>
            </div>

            <p class="mt-3 text-[11px] text-muted-foreground">
                Without an icon, the tile carries the project's initial.
            </p>
        </section>
    </div>
</template>
