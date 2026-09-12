<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarOff, ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import CalendarTaskChip from '@/modules/project/components/CalendarTaskChip.vue';
import type { CalendarCardData, ProjectCalendar } from '@/modules/task/types';
import { show } from '@/routes/projects';

const props = defineProps<{
    projectId: string;
    calendar: ProjectCalendar;
    tags: string[];
    editable: boolean;
    draggingId: string | null;
}>();

const emit = defineEmits<{
    open: [taskId: string];
    pickup: [event: PointerEvent, card: CalendarCardData];
    navigating: [active: boolean];
}>();

const address = (month: string): string =>
    show(props.projectId, { query: { view: 'calendar', month, tags: props.tags } }).url;

const shifted = (months: number): string => {
    const [year, month] = props.calendar.month.split('-').map(Number);
    const moved = new Date(year, month - 1 + months, 1);

    return `${moved.getFullYear()}-${String(moved.getMonth() + 1).padStart(2, '0')}`;
};

const label = computed<string>(() =>
    new Date(`${props.calendar.month}-01T00:00:00`).toLocaleDateString(undefined, { month: 'long', year: 'numeric' }),
);

const thisMonth = computed<string>(() => props.calendar.today.slice(0, 7));

const undated = computed(() => props.calendar.undated);
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <div class="inline-flex items-center overflow-hidden rounded-md border border-border">
            <Link
                :href="address(shifted(-1))"
                :only="['calendar']"
                preserve-state
                preserve-scroll
                class="inline-flex size-8 items-center justify-center text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                aria-label="Previous month"
                @start="emit('navigating', true)"
                @finish="emit('navigating', false)"
            >
                <ChevronLeft class="size-4" />
            </Link>

            <Link
                :href="address(thisMonth)"
                :only="['calendar']"
                preserve-state
                preserve-scroll
                class="inline-flex h-8 items-center border-x border-border px-2.5 text-[13px] font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                :class="
                    calendar.month === thisMonth
                        ? 'bg-accent text-foreground'
                        : 'text-muted-foreground hover:bg-accent hover:text-foreground'
                "
                @start="emit('navigating', true)"
                @finish="emit('navigating', false)"
            >
                Today
            </Link>

            <Link
                :href="address(shifted(1))"
                :only="['calendar']"
                preserve-state
                preserve-scroll
                class="inline-flex size-8 items-center justify-center text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                aria-label="Next month"
                @start="emit('navigating', true)"
                @finish="emit('navigating', false)"
            >
                <ChevronRight class="size-4" />
            </Link>
        </div>

        <h2 class="text-base font-semibold text-foreground first-letter:uppercase">{{ label }}</h2>

        <Popover v-if="undated.count > 0">
            <PopoverTrigger
                class="inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-2.5 md:ml-auto text-[13px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
            >
                <CalendarOff class="size-4" aria-hidden="true" />
                No date ({{ undated.count }})
            </PopoverTrigger>

            <PopoverContent align="end" class="w-72 p-1.5">
                <p class="px-1.5 pt-1 pb-2 text-xs text-muted-foreground">
                    {{ editable ? 'Drag one onto a day to schedule it.' : 'Nobody has given these a due date.' }}
                </p>

                <div class="flex max-h-72 flex-col gap-0.5 overflow-y-auto">
                    <CalendarTaskChip
                        v-for="card in undated.tasks"
                        :key="card.placementId"
                        :card="card"
                        :editable="editable"
                        :dragging="draggingId === card.placementId"
                        @open="emit('open', $event)"
                        @pickup="(event, dragged) => emit('pickup', event, dragged)"
                    />
                </div>

                <p v-if="undated.hasMore" class="px-1.5 pt-2 text-xs text-muted-foreground">
                    Showing {{ undated.tasks.length }} of {{ undated.count }}.
                </p>
            </PopoverContent>
        </Popover>
    </div>
</template>
