<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CalendarPlus, TriangleAlert, X } from '@lucide/vue';
import { computed, defineAsyncComponent, ref } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { dayOf, formatDay, isOverdue, today } from '@/lib/dueDate';

/** Sends only `due_at`; `tasks.update` leaves absent fields untouched. */
const props = defineProps<{
    taskId: string;
    dueAt: string | null;
    editable: boolean;
    variant?: 'inline' | 'field';
}>();

// The calendar chunk is heavy and lists render many triggers, so it loads on hover or focus.
const loadCalendar = () => import('@/modules/task/components/DueDateCalendar.vue');

const DueDateCalendar = defineAsyncComponent(loadCalendar);

const warmCalendar = (): void => void loadCalendar();

const open = ref(false);
const saving = ref(false);

const day = computed<string | null>(() => dayOf(props.dueAt));

const overdue = computed<boolean>(() => isOverdue(day.value));

const label = computed<string>(() => (day.value === null ? '' : formatDay(day.value)));

function save(next: string | null): void {
    saving.value = true;

    router.put(
        TaskController.update.url(props.taskId),
        { due_at: next },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = false;
                open.value = false;
            },
        },
    );
}

</script>

<template>
    <span
        v-if="!editable"
        class="inline-flex items-center gap-1 text-muted-foreground"
        :class="variant === 'field' ? 'text-sm' : 'text-xs'"
    >
        <TriangleAlert v-if="overdue" class="size-3.5 text-destructive" aria-hidden="true" />
        <span :class="overdue ? 'text-destructive' : ''">{{ label || '—' }}</span>
        <span v-if="overdue" class="sr-only">overdue</span>
    </span>

    <Popover v-else v-model:open="open">
        <PopoverTrigger
            :disabled="saving"
            @pointerenter="warmCalendar"
            @focus="warmCalendar"
            class="inline-flex min-h-11 items-center gap-1.5 rounded-md transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none disabled:opacity-50 md:min-h-6"
            :class="[
                overdue ? 'text-destructive' : 'text-muted-foreground',
                variant === 'field' ? 'px-1.5 py-1 text-sm md:min-h-8' : 'px-1 py-0.5 text-xs',
            ]"
            :aria-label="dueAt === null ? 'Set a due date' : `Due ${label}${overdue ? ', overdue' : ''}. Change it`"
        >
            <TriangleAlert v-if="overdue" class="size-3.5" aria-hidden="true" />
            <span
                v-if="dueAt === null"
                class="flex size-4 items-center justify-center rounded border border-dashed border-muted-foreground/50"
            >
                <CalendarPlus class="size-2.5" />
            </span>
            <span v-else>{{ label }}</span>

            <span v-if="variant === 'field' && dueAt === null">No due date</span>
        </PopoverTrigger>

        <PopoverContent class="w-auto p-0" align="start">
            <!-- Reserves the calendar's size while its chunk loads. -->
            <div class="min-h-[298px] w-[266px]">
                <DueDateCalendar :day="day" @pick="save" />
            </div>

            <div class="flex items-center gap-1 border-t border-border p-2">
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-8 flex-1 text-xs"
                    :disabled="saving"
                    @click="save(today())"
                >
                    Today
                </Button>

                <Button
                    v-if="dueAt !== null"
                    variant="ghost"
                    size="sm"
                    class="h-8 text-xs text-muted-foreground"
                    :disabled="saving"
                    aria-label="Clear the due date"
                    @click="save(null)"
                >
                    <X class="size-3.5" />
                    Clear
                </Button>
            </div>
        </PopoverContent>
    </Popover>
</template>
