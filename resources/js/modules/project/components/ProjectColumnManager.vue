<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronDown, ChevronUp, Lock } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ProjectColumnController from '@/actions/App/Http/Controllers/Project/ProjectColumnController';
import { Button } from '@/components/ui/button';
import type { ListColumn } from '@/modules/task/listColumns';

/**
 * The order the list draws its columns in.
 *
 * The task name is drawn here and cannot be moved. It is always the first column — a list whose
 * titles are in the fourth column is a list nobody can scan — so it is shown rather than hidden,
 * because a reader looking for it and not finding it would conclude it had been lost.
 *
 * Up and down rather than dragging, which is what `SectionManager` does one screen away: the list
 * is short, every move is reachable from the keyboard, and a drag needs a second answer for
 * touch. The whole order goes in one request, so a move that fails leaves the order it had.
 */
const props = defineProps<{
    projectId: string;
    columns: ListColumn[];
    canManage: boolean;
}>();

/*
 * The order being edited. Local, because a move should redraw the moment it is clicked rather
 * than a request later — and replaced whenever the server sends a new order, which is what makes
 * the server's answer the one that wins.
 */
const order = ref<ListColumn[]>([...props.columns]);

watch(() => props.columns, (next) => (order.value = [...next]), { deep: true });

/**
 * A move landed. The drawer's props are `Inertia::optional` and the redirect does not carry them,
 * so the drawer asks for its own again — the same arrangement the field list uses.
 */
const emit = defineEmits<{ changed: [] }>();

const pending = ref(false);

function move(index: number, by: number): void {
    const target = index + by;

    if (target < 0 || target >= order.value.length) {
        return;
    }

    const next = [...order.value];
    [next[index], next[target]] = [next[target], next[index]];
    order.value = next;

    save();
}

function save(): void {
    pending.value = true;

    router.put(
        ProjectColumnController.update.url(props.projectId),
        { columns: order.value.map((column) => column.key) },
        {
            preserveScroll: true,
            onSuccess: () => emit('changed'),
            onFinish: () => (pending.value = false),
        },
    );
}

const lastIndex = computed(() => order.value.length - 1);
</script>

<template>
    <div class="space-y-4">
        <ul class="divide-y rounded-lg border">
            <!-- Pinned, and said so rather than merely disabled: a row with two dead buttons and
                 no reason reads as a bug. -->
            <li class="text-muted-foreground flex items-center gap-2 px-3 py-2">
                <Lock class="size-3.5 shrink-0" aria-hidden="true" />
                <span class="flex-1 text-sm">Task name</span>
                <span class="text-xs">Always first</span>
            </li>

            <li
                v-for="(column, index) in order"
                :key="column.key"
                class="flex items-center gap-2 px-3 py-1.5"
            >
                <span class="min-w-0 flex-1 truncate text-sm">{{ column.label }}</span>

                <div v-if="props.canManage" class="text-muted-foreground flex items-center gap-0.5">
                    <Button
                        size="icon-sm"
                        variant="ghost"
                        :disabled="index === 0 || pending"
                        :aria-label="`Move ${column.label} up`"
                        @click="move(index, -1)"
                    >
                        <ChevronUp class="size-4" />
                    </Button>
                    <Button
                        size="icon-sm"
                        variant="ghost"
                        :disabled="index === lastIndex || pending"
                        :aria-label="`Move ${column.label} down`"
                        @click="move(index, 1)"
                    >
                        <ChevronDown class="size-4" />
                    </Button>
                </div>
            </li>
        </ul>

        <p class="text-muted-foreground text-xs">
            This is the order the list draws its columns in, for everybody who opens the project.
        </p>
    </div>
</template>
