<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import TaskTagController from '@/actions/App/Http/Controllers/Tag/TaskTagController';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { accentChipClass, accentVars } from '@/lib/accentColor';
import type { TaskTag } from '@/modules/task/types';

const props = defineProps<{
    taskId: string;
    tags: TaskTag[];
    available: TaskTag[];
    editable: boolean;
    canCreate: boolean;
}>();

const picking = ref(false);
const query = ref('');
const search = ref<HTMLInputElement | null>(null);

const unused = computed<TaskTag[]>(() => {
    const applied = new Set(props.tags.map((tag) => tag.id));

    return props.available.filter((tag) => !applied.has(tag.id));
});

const matches = computed<TaskTag[]>(() => {
    const needle = query.value.trim().toLowerCase();

    return needle === ''
        ? unused.value
        : unused.value.filter((tag) => tag.name.toLowerCase().includes(needle));
});

// Checked against every tag, so one already on the task is not offered for creation.
const isNew = computed(() => {
    const needle = query.value.trim().toLowerCase();

    return (
        needle !== '' &&
        !props.available.some((tag) => tag.name.toLowerCase() === needle)
    );
});

const canInvent = computed(() => props.canCreate && isNew.value);

function open(next: boolean): void {
    picking.value = next;
    query.value = '';

    if (next) {
        void nextTick(() => search.value?.focus());
    }
}

function attach(payload: { tag: string } | { name: string }): void {
    open(false);

    router.post(TaskTagController.store.url(props.taskId), payload, {
        preserveScroll: true,
    });
}

function confirm(): void {
    if (matches.value.length === 1) {
        attach({ tag: matches.value[0].id });

        return;
    }

    if (canInvent.value) {
        attach({ name: query.value.trim() });
    }
}

const remove = (tag: TaskTag): void => {
    router.delete(
        TaskTagController.destroy.url({ task: props.taskId, tag: tag.id }),
        { preserveScroll: true },
    );
};
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5">
        <span
            v-for="tag in tags"
            :key="tag.id"
            class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium"
            :class="accentChipClass(tag.color)"
            :style="accentVars(tag.color)"
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

        <Popover v-if="editable" :open="picking" @update:open="open">
            <PopoverTrigger
                class="inline-flex items-center gap-1 rounded-md border border-dashed border-muted-foreground/50 px-1.5 py-0.5 text-xs text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                :aria-label="tags.length ? 'Add another tag' : 'Add a tag'"
            >
                <Plus class="size-3" />
                <span v-if="!tags.length">Add tag</span>
            </PopoverTrigger>

            <PopoverContent align="start" class="w-60 p-1">
                <input
                    ref="search"
                    v-model="query"
                    type="text"
                    maxlength="40"
                    class="mb-1 h-8 w-full rounded-md border border-input bg-transparent px-2 text-sm focus:outline-none"
                    :placeholder="
                        canCreate ? 'Find or create a tag' : 'Find a tag'
                    "
                    aria-label="Find or create a tag"
                    @keydown.enter.prevent="confirm"
                />

                <ul class="max-h-56 overflow-y-auto">
                    <li v-for="tag in matches" :key="tag.id">
                        <button
                            type="button"
                            class="flex w-full items-center rounded-md px-2 py-1.5 text-left transition-colors hover:bg-accent"
                            @click="attach({ tag: tag.id })"
                        >
                            <span
                                class="rounded-md px-2 py-0.5 text-xs font-medium"
                                :class="accentChipClass(tag.color)"
                                :style="accentVars(tag.color)"
                            >
                                {{ tag.name }}
                            </span>
                        </button>
                    </li>
                </ul>

                <button
                    v-if="canInvent"
                    type="button"
                    class="flex w-full items-center gap-1.5 rounded-md px-2 py-1.5 text-left text-sm transition-colors hover:bg-accent"
                    @click="attach({ name: query.trim() })"
                >
                    <Plus class="size-3.5 shrink-0 text-muted-foreground" />
                    <span class="truncate">Create “{{ query.trim() }}”</span>
                </button>

                <p
                    v-else-if="!matches.length"
                    class="px-2 py-1.5 text-xs text-muted-foreground"
                >
                    {{
                        query.trim()
                            ? 'No tag by that name.'
                            : 'Every tag is already on this task.'
                    }}
                </p>
            </PopoverContent>
        </Popover>

        <span
            v-else-if="!tags.length"
            class="px-1.5 text-sm text-muted-foreground"
            >None</span
        >
    </div>
</template>
