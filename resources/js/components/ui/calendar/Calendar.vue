<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import type { DateValue } from '@internationalized/date';
import {
    CalendarCell,
    CalendarCellTrigger,
    CalendarGrid,
    CalendarGridBody,
    CalendarGridHead,
    CalendarGridRow,
    CalendarHeadCell,
    CalendarHeader,
    CalendarHeading,
    CalendarNext,
    CalendarPrev,
    CalendarRoot,
} from 'reka-ui';

defineProps<{ modelValue?: DateValue }>();

defineEmits<{ 'update:modelValue': [DateValue | undefined] }>();
</script>

<template>
    <CalendarRoot
        v-slot="{ grid, weekDays }"
        :model-value="modelValue"
        class="p-3"
        @update:model-value="(value) => $emit('update:modelValue', value as DateValue | undefined)"
    >
        <CalendarHeader class="flex items-center justify-between pb-3">
            <CalendarPrev
                class="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                aria-label="Previous month"
            >
                <ChevronLeft class="size-4" />
            </CalendarPrev>

            <CalendarHeading class="text-sm font-medium" />

            <CalendarNext
                class="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                aria-label="Next month"
            >
                <ChevronRight class="size-4" />
            </CalendarNext>
        </CalendarHeader>

        <CalendarGrid v-for="month in grid" :key="month.value.toString()" class="w-full border-collapse">
            <CalendarGridHead>
                <CalendarGridRow class="flex">
                    <CalendarHeadCell
                        v-for="day in weekDays"
                        :key="day"
                        class="w-8 text-[11px] font-medium text-muted-foreground"
                    >
                        {{ day }}
                    </CalendarHeadCell>
                </CalendarGridRow>
            </CalendarGridHead>

            <CalendarGridBody>
                <CalendarGridRow
                    v-for="(week, index) in month.rows"
                    :key="`week-${index}`"
                    class="flex w-full"
                >
                    <CalendarCell v-for="day in week" :key="day.toString()" :date="day" class="p-0">
                        <CalendarCellTrigger
                            :day="day"
                            :month="month.value"
                            class="flex size-8 items-center justify-center rounded-md text-sm transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none data-[disabled]:pointer-events-none data-[outside-view]:text-muted-foreground/40 data-[selected]:bg-primary data-[selected]:text-primary-foreground data-[today]:ring-1 data-[today]:ring-primary/50 data-[selected]:data-[today]:ring-0"
                        />
                    </CalendarCell>
                </CalendarGridRow>
            </CalendarGridBody>
        </CalendarGrid>
    </CalendarRoot>
</template>
