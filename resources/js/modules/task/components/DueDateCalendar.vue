<script setup lang="ts">
import { CalendarDate } from '@internationalized/date';
import type { DateValue } from '@internationalized/date';
import { computed } from 'vue';
import { Calendar } from '@/components/ui/calendar';

/**
 * The month a due date is picked out of, and the only component that loads
 * `@internationalized/date` — 26 kB that a list of rows would otherwise carry to draw a picker
 * none of them has opened. `DueDatePicker` imports this asynchronously, so the library arrives
 * with the popover.
 *
 * A day goes in as `YYYY-MM-DD` and comes back the same way: the calendar's own type belongs to
 * the calendar.
 */
const props = defineProps<{ day: string | null }>();

const emit = defineEmits<{ pick: [day: string | null] }>();

const value = computed<DateValue | undefined>(() => {
    if (props.day === null) {
        return undefined;
    }

    const [year, month, date] = props.day.split('-').map(Number);

    return new CalendarDate(year, month, date);
});

const choose = (picked: DateValue | undefined): void =>
    emit('pick', picked === undefined ? null : picked.toString());
</script>

<template>
    <Calendar :model-value="value" @update:model-value="choose" />
</template>
