<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronDown, ChevronUp, Lock } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ProjectColumnController from '@/actions/App/Http/Controllers/Project/ProjectColumnController';
import { Button } from '@/components/ui/button';
import type { ListColumn } from '@/modules/task/listColumns';

const props = defineProps<{
    projectId: string;
    columns: ListColumn[];
    canManage: boolean;
}>();

const order = ref<ListColumn[]>([...props.columns]);

watch(
    () => props.columns,
    (next) => (order.value = [...next]),
    { deep: true },
);

/** The redirect omits the drawer's optional props, so the parent reloads them on `changed`. */
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
            <li class="flex items-center gap-2 px-3 py-2 text-muted-foreground">
                <Lock class="size-3.5 shrink-0" aria-hidden="true" />
                <span class="flex-1 text-sm">Task name</span>
                <span class="text-xs">Always first</span>
            </li>

            <li
                v-for="(column, index) in order"
                :key="column.key"
                class="flex items-center gap-2 px-3 py-1.5"
            >
                <span class="min-w-0 flex-1 truncate text-sm">{{
                    column.label
                }}</span>

                <div
                    v-if="props.canManage"
                    class="flex items-center gap-0.5 text-muted-foreground"
                >
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

        <p class="text-xs text-muted-foreground">
            This is the order the list draws its columns in, for everybody who
            opens the project.
        </p>
    </div>
</template>
