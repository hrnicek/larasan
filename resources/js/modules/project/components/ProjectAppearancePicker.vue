<script setup lang="ts">
import { ref } from 'vue';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import ProjectAppearanceFields from '@/modules/project/components/ProjectAppearanceFields.vue';
import ProjectTile from '@/modules/project/components/ProjectTile.vue';

/**
 * The project's own tile, and what it looks like.
 *
 * Clicking the thing you want to change is the shortest route to changing it, so the tile in the
 * header is the control rather than a link to a form that also holds the description, the dates
 * and the visibility. The endpoint behind it writes those two columns and nothing else.
 */
const props = defineProps<{
    project: { id: string; name: string; color: string | null; icon: string | null };
}>();

const open = ref(false);
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger
            class="rounded-lg focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
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

        <PopoverContent class="w-80 p-3" align="start">
            <ProjectAppearanceFields :project="props.project" />
        </PopoverContent>
    </Popover>
</template>
