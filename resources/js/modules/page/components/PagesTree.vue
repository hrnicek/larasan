<script setup lang="ts">
import PageTreePanel from '@/modules/page/components/PageTreePanel.vue';
import type { ProjectPages } from '@/modules/page/types';

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
