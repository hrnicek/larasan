<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CalendarDate,  getLocalTimeZone, today } from '@internationalized/date';
import type {DateValue} from '@internationalized/date';
import { CalendarPlus, TriangleAlert, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

/**
 * The due date, changed from the row or the panel. Only this field is sent: `tasks.update`
 * treats an absent field as untouched, so a date change cannot clear a description the row
 * never carried.
 *
 * A calendar rather than the browser's date input. The native control draws its own placeholder
 * in every row of a list where most tasks have no date, opens differently in every browser, and
 * cannot be given *Today* or *Clear* — which are the two things anybody actually wants from a due
 * date.
 */
const props = defineProps<{
    taskId: string;
    dueAt: string | null;
    editable: boolean;
}>();

const open = ref(false);
const saving = ref(false);

const date = computed<string>(() => (props.dueAt === null ? '' : props.dueAt.slice(0, 10)));

/** The stored day as the calendar's own type. Date-only: a due date is a day, not an instant. */
const value = computed<DateValue | undefined>(() => {
    if (date.value === '') {
        return undefined;
    }

    const [year, month, day] = date.value.split('-').map(Number);

    return new CalendarDate(year, month, day);
});

/**
 * Overdue is said twice — in red **and** with an icon — because colour alone is not a message
 * somebody who cannot see it receives. Compared as days rather than instants: a task due today
 * is not late at nine in the morning.
 */
const overdue = computed<boolean>(() => value.value !== undefined && value.value.compare(today(getLocalTimeZone())) < 0);

/** The reader's own locale, short: `12 Aug` is a date, `2026-08-12` is a value. */
const label = computed<string>(() =>
    value.value === undefined
        ? ''
        : value.value
              .toDate(getLocalTimeZone())
              .toLocaleDateString(undefined, { day: 'numeric', month: 'short' }),
);

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

const choose = (picked: DateValue | undefined): void => save(picked === undefined ? null : picked.toString());
</script>

<template>
    <span v-if="!editable" class="inline-flex items-center gap-1 text-xs text-muted-foreground">
        <TriangleAlert v-if="overdue" class="size-3.5 text-destructive" aria-hidden="true" />
        <span :class="overdue ? 'text-destructive' : ''">{{ label || '—' }}</span>
        <span v-if="overdue" class="sr-only">overdue</span>
    </span>

    <Popover v-else v-model:open="open">
        <!--
            An empty cell is a target rather than a gap: the dashed outline says "something goes
            here" the way the reference's placeholder does, instead of leaving a browser's
            `dd.mm.yyyy` in every row of a list where most tasks have no date.
        -->
        <PopoverTrigger
            :disabled="saving"
            class="inline-flex min-h-11 items-center gap-1 rounded-md px-1 py-0.5 text-xs transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none disabled:opacity-50 md:min-h-6"
            :class="overdue ? 'text-destructive' : 'text-muted-foreground'"
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
        </PopoverTrigger>

        <PopoverContent class="w-auto p-0" align="start">
            <Calendar :model-value="value" @update:model-value="choose" />

            <!-- The two things anybody actually wants from a due date, and neither is a month grid. -->
            <div class="flex items-center gap-1 border-t border-border p-2">
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-8 flex-1 text-xs"
                    :disabled="saving"
                    @click="save(today(getLocalTimeZone()).toString())"
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
