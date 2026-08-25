<script setup lang="ts">
import ChromeNavItem from '@/components/ChromeNavItem.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import ProjectContextMenu from '@/modules/project/components/ProjectContextMenu.vue';
import ProjectTile from '@/modules/project/components/ProjectTile.vue';
import type { SidebarProject } from '@/modules/project/types';
import { show } from '@/routes/projects';

/**
 * One project in the sidebar: the link, its tile and the menu a right click opens.
 *
 * Its own component because the rail draws the same row in two groups — starred and the rest —
 * and a second copy of it would be a second place for the menu to go out of date.
 */
const props = defineProps<{ project: SidebarProject }>();

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <ProjectContextMenu :project="props.project">
        <ChromeNavItem
            :href="show(props.project.id).url"
            :label="props.project.name"
            :active="isCurrentUrl(show(props.project.id).url)"
        >
            <template #icon>
                <!--
                    The same tile collapsed and expanded. A bare glyph beside a name says what
                    kind of project it is rather than which one: the colour is what tells two
                    boards apart at a glance, and the tile carries the project's first letter
                    when it has no icon at all.
                -->
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
