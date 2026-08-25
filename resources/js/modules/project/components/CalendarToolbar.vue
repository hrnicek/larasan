<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarOff, ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import CalendarTaskChip from '@/modules/project/components/CalendarTaskChip.vue';
import type { CalendarCardData, ProjectCalendar } from '@/modules/task/types';
import { show } from '@/routes/projects';

/**
 * Which month you are looking at, and the work that is not in any month.
 *
 * The month lives in the URL rather than in local state, for the reason the view does: a reload
 * and a shared link should both show what the sender was looking at. Every control here is a
 * real link, so a middle-click opens next month in a tab and the back button walks back through
 * the months somebody paged through.
 */
const props = defineProps<{
    projectId: string;
    calendar: ProjectCalendar;
    /** Carried through every link, so paging months does not silently drop the tag filter. */
    tags: string[];
    editable: boolean;
    draggingId: string | null;
}>();

const emit = defineEmits<{
    open: [taskId: string];
    pickup: [event: PointerEvent, card: CalendarCardData];
}>();

const address = (month: string): string =>
    show(props.projectId, { query: { view: 'calendar', month, tags: props.tags } }).url;

/** `YYYY-MM`, moved by whole months — the string the URL carries and the server validates. */
const shifted = (months: number): string => {
    const [year, month] = props.calendar.month.split('-').map(Number);
    const moved = new Date(year, month - 1 + months, 1);

    return `${moved.getFullYear()}-${String(moved.getMonth() + 1).padStart(2, '0')}`;
};

const label = computed<string>(() =>
    new Date(`${props.calendar.month}-01T00:00:00`).toLocaleDateString(undefined, { month: 'long', year: 'numeric' }),
);

/** The month today falls in, so *Today* is a link like the arrows rather than a special case. */
const thisMonth = computed<string>(() => props.calendar.today.slice(0, 7));

const undated = computed(() => props.calendar.undated);
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5">
        <Link
            :href="address(shifted(-1))"
            class="inline-flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
            aria-label="Previous month"
            preserve-scroll
        >
            <ChevronLeft class="size-4" />
        </Link>

        <Link
            :href="address(thisMonth)"
            class="inline-flex h-8 items-center rounded-md px-2.5 text-[13px] font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
            :class="
                calendar.month === thisMonth
                    ? 'bg-accent text-foreground'
                    : 'text-muted-foreground hover:bg-accent hover:text-foreground'
            "
            preserve-scroll
        >
            Today
        </Link>

        <Link
            :href="address(shifted(1))"
            class="inline-flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
            aria-label="Next month"
            preserve-scroll
        >
            <ChevronRight class="size-4" />
        </Link>

        <h2 class="ml-1 text-sm font-semibold text-foreground">{{ label }}</h2>

        <!--
            Work with no due date. Counted rather than hidden: a month that draws only what is
            scheduled would quietly answer "this project has nothing left" while the tray holds
            seventeen things nobody has dated.
        -->
        <Popover v-if="undated.count > 0">
            <PopoverTrigger
                class="ml-auto inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-2.5 text-[13px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
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
