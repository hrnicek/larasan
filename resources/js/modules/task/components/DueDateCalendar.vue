<script setup lang="ts">
import { CalendarDate } from '@internationalized/date';
import type { DateValue } from '@internationalized/date';
import { computed } from 'vue';
import { Calendar } from '@/components/ui/calendar';

/** Keeps `@internationalized/date` in an async chunk; days go in and out as `YYYY-MM-DD`. */
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
