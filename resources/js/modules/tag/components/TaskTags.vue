<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import TaskTagController from '@/actions/App/Http/Controllers/Tag/TaskTagController';
import { accentTextClass } from '@/lib/accentColor';
import type { TaskTag } from '@/modules/task/types';

/**
 * What a task is about.
 *
 * Applying a tag is editing the task, so the control follows `editable` — the same flag every
 * other field on this screen follows. Making a tag is a different permission and a different
 * screen; this one offers what the workspace already has.
 */
const props = defineProps<{
    taskId: string;
    tags: TaskTag[];
    available: TaskTag[];
    editable: boolean;
}>();

const picking = ref(false);

const unused = computed<TaskTag[]>(() => {
    const applied = new Set(props.tags.map((tag) => tag.id));

    return props.available.filter((tag) => !applied.has(tag.id));
});

const add = (tag: TaskTag): void => {
    picking.value = false;

    router.post(TaskTagController.store.url(props.taskId), { tag: tag.id }, { preserveScroll: true });
};

const remove = (tag: TaskTag): void => {
    router.delete(TaskTagController.destroy.url({ task: props.taskId, tag: tag.id }), { preserveScroll: true });
};
</script>

<template>
    <section class="flex flex-col gap-2">
        <h3 class="text-xs text-muted-foreground">Tags</h3>

        <div class="flex flex-wrap items-center gap-2">
            <span
                v-for="tag in tags"
                :key="tag.id"
                class="flex items-center gap-1 rounded border border-input px-1.5 py-0.5 text-xs"
                :class="accentTextClass(tag.color)"
            >
                {{ tag.name }}

                <button
                    v-if="editable"
                    type="button"
                    class="text-muted-foreground"
                    :aria-label="`Remove ${tag.name}`"
                    @click="remove(tag)"
                >
                    ×
                </button>
            </span>

            <p v-if="!tags.length" class="text-sm text-muted-foreground">No tags.</p>

            <button
                v-if="editable && unused.length"
                type="button"
                class="rounded border border-input px-1.5 py-0.5 text-xs text-muted-foreground"
                @click="picking = !picking"
            >
                + Add tag
            </button>
        </div>

        <ul v-if="picking" class="flex flex-wrap gap-2">
            <li v-for="tag in unused" :key="tag.id">
                <button
                    type="button"
                    class="rounded border border-input px-1.5 py-0.5 text-xs"
                    :class="accentTextClass(tag.color)"
                    @click="add(tag)"
                >
                    {{ tag.name }}
                </button>
            </li>
        </ul>
    </section>
</template>
