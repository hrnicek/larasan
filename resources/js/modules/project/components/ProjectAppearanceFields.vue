<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import ProjectAppearanceController from '@/actions/App/Http/Controllers/Project/ProjectAppearanceController';
import AccentColorGrid from '@/modules/project/components/AccentColorGrid.vue';
import ProjectIconGrid from '@/modules/project/components/ProjectIconGrid.vue';

const props = defineProps<{
    project: {
        id: string;
        name: string;
        color: string | null;
        icon: string | null;
    };
}>();

const saving = ref(false);

function save(color: string | null, icon: string | null): void {
    saving.value = true;

    router.put(
        ProjectAppearanceController.update.url(props.project.id),
        { color, icon },
        {
            preserveScroll: true,
            // Keeps the surrounding popover or menu open between picks.
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
