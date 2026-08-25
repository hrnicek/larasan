<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import TaskTagController from '@/actions/App/Http/Controllers/Tag/TaskTagController';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { accentChipClass } from '@/lib/accentColor';
import type { TaskTag } from '@/modules/task/types';

/**
 * What a task is about, as a field rather than as a section: a label on the left, its chips on
 * the right, aligned with the assignee and the due date above it.
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
    <div class="flex flex-wrap items-center gap-1.5">
        <span
            v-for="tag in tags"
            :key="tag.id"
            class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium"
            :class="accentChipClass(tag.color)"
        >
            {{ tag.name }}

            <button
                v-if="editable"
                type="button"
                class="-mr-0.5 rounded transition-opacity hover:opacity-70 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                :aria-label="`Remove ${tag.name}`"
                @click="remove(tag)"
            >
                <X class="size-3" />
            </button>
        </span>

        <!--
            Empty is a target rather than a gap, the way the assignee and the due date are: the
            dashed outline says something goes here instead of leaving a word to aim at.
        -->
        <Popover v-if="editable && unused.length" v-model:open="picking">
            <PopoverTrigger
                class="inline-flex items-center gap-1 rounded-md border border-dashed border-muted-foreground/50 px-1.5 py-0.5 text-xs text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                :aria-label="tags.length ? 'Add another tag' : 'Add a tag'"
            >
                <Plus class="size-3" />
                <span v-if="!tags.length">Add tag</span>
            </PopoverTrigger>

            <PopoverContent align="start" class="w-56 p-1">
                <ul class="max-h-56 overflow-y-auto">
                    <li v-for="tag in unused" :key="tag.id">
                        <button
                            type="button"
                            class="flex w-full items-center rounded-md px-2 py-1.5 text-left transition-colors hover:bg-accent"
                            @click="add(tag)"
                        >
                            <span
                                class="rounded-md px-2 py-0.5 text-xs font-medium"
                                :class="accentChipClass(tag.color)"
                            >
                                {{ tag.name }}
                            </span>
                        </button>
                    </li>
                </ul>
            </PopoverContent>
        </Popover>

        <span v-else-if="!tags.length" class="px-1.5 text-sm text-muted-foreground">None</span>
    </div>
</template>
