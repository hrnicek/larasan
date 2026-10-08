<script setup lang="ts">
import ChromeNavItem from '@/components/ChromeNavItem.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import ProjectContextMenu from '@/modules/project/components/ProjectContextMenu.vue';
import ProjectTile from '@/modules/project/components/ProjectTile.vue';
import type { SidebarProject } from '@/modules/project/types';
import { show } from '@/routes/projects';

const props = defineProps<{ project: SidebarProject }>();

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <ProjectContextMenu :project="props.project">
        <ChromeNavItem
            :href="show(props.project.id).url"
            :label="props.project.name"
            component="projects/Show"
            :active="isCurrentUrl(show(props.project.id).url)"
        >
            <template #icon>
                <ProjectTile
                    :name="props.project.name"
                    :color="props.project.color"
                    :icon="props.project.icon"
                    size="sm"
                    surface="chrome"
                />
            </template>
        </ChromeNavItem>
    </ProjectContextMenu>
</template>
