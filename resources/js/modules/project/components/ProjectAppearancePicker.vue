<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { ref } from 'vue';
import ProjectAppearanceController from '@/actions/App/Http/Controllers/Project/ProjectAppearanceController';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { accentColorNames, accentDotClass } from '@/lib/accentColor';
import { projectIconComponent, projectIconLabel, projectIconNames } from '@/lib/projectIcon';
import ProjectTile from '@/modules/project/components/ProjectTile.vue';

/**
 * The project's own tile, and what it looks like.
 *
 * Clicking the thing you want to change is the shortest route to changing it, so the tile in the
 * header is the control rather than a link to a form that also holds the description, the dates
 * and the visibility. The endpoint behind it writes those two columns and nothing else.
 *
 * The picker keeps no draft of its own: each pick is sent, and the tile re-renders from the props
 * that come back. Optimism belongs to board cards, where a rollback is a card sliding back to
 * where it was — here a failed write would leave the header claiming a colour the project lost.
 */
const props = defineProps<{
    project: { id: string; name: string; color: string | null; icon: string | null };
}>();

const open = ref(false);
const saving = ref(false);

function save(color: string | null, icon: string | null): void {
    saving.value = true;

    router.put(
        ProjectAppearanceController.update.url(props.project.id),
        { color, icon },
        {
            preserveScroll: true,
            // The popover stays open: picking a colour and then an icon is one errand, and a
            // control that closes itself after each pick makes it two.
            preserveState: true,
            onFinish: () => (saving.value = false),
        },
    );
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger
            class="rounded-lg focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
            :disabled="saving"
            :aria-label="`Colour and icon for ${props.project.name}`"
        >
            <ProjectTile
                :name="props.project.name"
                :color="props.project.color"
                :icon="props.project.icon"
                size="lg"
                class="transition-opacity hover:opacity-80"
            />
        </PopoverTrigger>

        <PopoverContent class="w-72 p-3" align="start">
            <section>
                <div class="flex h-6 items-center justify-between">
                    <h3 class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">Colour</h3>

                    <!-- Clearing is its own control rather than a swatch: an empty square in a row of
                         colours reads as one more colour, and a neutral one reads as slate. -->
                    <button
                        v-if="props.project.color"
                        type="button"
                        class="rounded px-1 text-[11px] text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
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
                        @click="save(props.project.color, null)"
                    >
                        Clear
                    </button>
                </div>

                <!-- Bounded and scrollable: the library is four rows today and the popover should not
                     grow past the header when it is not. -->
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
                        @click="save(props.project.color, icon)"
                    >
                        <component :is="projectIconComponent(icon)" class="size-4" />
                    </button>
                </div>

                <p class="mt-3 text-[11px] text-muted-foreground">
                    Without an icon, the tile carries the project's initial.
                </p>
            </section>
        </PopoverContent>
    </Popover>
</template>
