<script setup lang="ts">
import { CheckSquare, MessageSquare, MoveRight, TriangleAlert, UserRound } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserAvatar from '@/components/UserAvatar.vue';
import { accentChipClass } from '@/lib/accentColor';
import { dayOf, formatDay, isOverdue } from '@/lib/dueDate';
import type { BoardCardData, BoardColumnData } from '@/modules/task/types';

/**
 * One card on the board: what it is called, who has it, when it is due, how much conversation
 * it has and what it is about.
 *
 * A card is read at a glance and in bulk, so every fact on it is a shape rather than a sentence:
 * an avatar instead of a name, a count beside its icon instead of the word "comments", a chip
 * instead of a bordered word. What survives that compression is the title, which is why it is
 * the only thing here at full size.
 */
const props = defineProps<{
    card: BoardCardData;
    editable: boolean;
    dragging: boolean;
    columns: BoardColumnData[];
}>();

const emit = defineEmits<{
    pickup: [event: PointerEvent, card: BoardCardData];
    moveto: [placementId: string, columnKey: string];
    open: [taskId: string];
}>();

const keyOf = (column: BoardColumnData): string => column.id ?? 'ungrouped';

const day = computed<string | null>(() => dayOf(props.card.dueAt));

/** Overdue is red **and** carries an icon **and** says so in the label: colour alone is not a message. */
const overdue = computed<boolean>(() => props.card.completedAt === null && isOverdue(day.value));

const dueLabel = computed<string>(() => (day.value === null ? '' : formatDay(day.value)));
</script>

<template>
    <article
        tabindex="0"
        data-task-card
        :data-task-id="card.id"
        :data-placement-id="card.placementId"
        class="group/card rounded-md border border-border bg-card p-3 text-sm outline-none transition-shadow hover:shadow-xs focus-visible:ring-2 focus-visible:ring-primary-ring"
        :class="[
            card.completedAt ? 'text-muted-foreground' : '',
            dragging ? 'opacity-50' : '',
            editable ? 'cursor-grab touch-none active:cursor-grabbing' : '',
        ]"
        @pointerdown="editable ? emit('pickup', $event, card) : undefined"
        @keydown.enter="emit('open', card.id)"
    >
        <div v-if="card.tags.length" class="mb-2 flex flex-wrap gap-1">
            <span
                v-for="tag in card.tags"
                :key="tag.id"
                class="rounded px-1.5 py-0.5 text-[11px] font-medium"
                :class="accentChipClass(tag.color)"
            >
                {{ tag.name }}
            </span>
        </div>

        <button
            type="button"
            class="line-clamp-3 w-full text-left"
            :class="card.completedAt ? 'line-through' : ''"
            @click="emit('open', card.id)"
        >
            {{ card.title }}
        </button>

        <div class="mt-2.5 flex items-center gap-2.5 text-xs text-muted-foreground">
            <UserAvatar v-if="card.assignee" :user="card.assignee" size="sm" />
            <!-- Unassigned says so with the same silhouette the list uses. An empty dashed box
                 reads as something that failed to load. -->
            <span
                v-else
                class="flex size-6 shrink-0 items-center justify-center rounded-md border border-dashed border-muted-foreground/40"
                title="Unassigned"
            >
                <UserRound class="size-3.5 text-muted-foreground/60" aria-hidden="true" />
                <span class="sr-only">Unassigned</span>
            </span>

            <span
                v-if="dueLabel"
                class="inline-flex items-center gap-1"
                :class="overdue ? 'text-destructive' : ''"
            >
                <TriangleAlert v-if="overdue" class="size-3.5" aria-hidden="true" />
                {{ dueLabel }}
                <span v-if="overdue" class="sr-only">overdue</span>
            </span>

            <span v-if="card.subtasks > 0" class="inline-flex items-center gap-1">
                <CheckSquare class="size-3.5" aria-hidden="true" />
                {{ card.subtasks }}
                <span class="sr-only">subtasks</span>
            </span>

            <span v-if="card.comments > 0" class="inline-flex items-center gap-1">
                <MessageSquare class="size-3.5" aria-hidden="true" />
                {{ card.comments }}
                <span class="sr-only">comments</span>
            </span>

            <!--
                Moving without dragging: the phone's path, where a drag across a pager is a
                gesture nobody can land — and a perfectly good one with a mouse too. Revealed on
                hover or focus, because it is the card's action rather than one of its facts.
            -->
            <DropdownMenu v-if="editable">
                <DropdownMenuTrigger
                    class="ml-auto inline-flex size-6 items-center justify-center rounded-md opacity-0 transition-opacity group-focus-within/card:opacity-100 group-hover/card:opacity-100 hover:bg-accent hover:text-foreground focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    aria-label="Move to another column"
                >
                    <MoveRight class="size-3.5" />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        v-for="column in columns"
                        :key="keyOf(column)"
                        @select="emit('moveto', card.placementId, keyOf(column))"
                    >
                        {{ column.name ?? 'No section' }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </article>
</template>
