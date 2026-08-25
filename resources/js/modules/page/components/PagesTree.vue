<script setup lang="ts">
import PageTreePanel from '@/modules/page/components/PageTreePanel.vue';
import type { ProjectPages } from '@/modules/page/types';

/**
 * The project's fifth view. The tree itself is `PageTreePanel`, which the page screen's sidebar
 * draws too — one tree, one set of controls, one answer about what a row does.
 */
defineProps<{
    projectId: string;
    pages: ProjectPages;
}>();
</script>

<template>
    <div class="px-4 pt-4 md:px-6">
        <PageTreePanel :project-id="projectId" :pages="pages" variant="view" />
    </div>
</template>
