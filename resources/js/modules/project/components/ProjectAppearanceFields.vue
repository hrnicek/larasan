<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import ProjectAppearanceController from '@/actions/App/Http/Controllers/Project/ProjectAppearanceController';
import AccentColorGrid from '@/modules/project/components/AccentColorGrid.vue';
import ProjectIconGrid from '@/modules/project/components/ProjectIconGrid.vue';

/**
 * The palette and the icon library, and what happens when one of them is clicked.
 *
 * Its own component because two surfaces offer the same two choices — the tile in the project
 * header opens it in a popover, the sidebar row opens it in a submenu of its context menu. The
 * grids themselves live one level down, in `AccentColorGrid` and `ProjectIconGrid`, which the
 * settings form draws too without this component's saving behaviour.
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
        <AccentColorGrid
            :model-value="props.project.color"
            :disabled="saving"
            @update:model-value="save($event, props.project.icon)"
        />

        <ProjectIconGrid
            class="mt-4"
            :model-value="props.project.icon"
            :disabled="saving"
            @update:model-value="save(props.project.color, $event)"
        />
    </div>
</template>
