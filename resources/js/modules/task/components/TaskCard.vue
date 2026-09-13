<script setup lang="ts">
import {
    CheckSquare,
    MessageSquare,
    MoveRight,
    TriangleAlert,
    UserRound,
} from '@lucide/vue';
import { computed } from 'vue';
import AttachmentController from '@/actions/App/Http/Controllers/File/AttachmentController';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserAvatar from '@/components/UserAvatar.vue';
import { accentChipClass, accentVars } from '@/lib/accentColor';
import { dayOf, formatDay, isOverdue } from '@/lib/dueDate';
import type { BoardCardData, BoardColumnData } from '@/modules/task/types';

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

const down = (event: PointerEvent): void => {
    if (props.editable) {
        emit('pickup', event, props.card);
    }
};

const day = computed<string | null>(() => dayOf(props.card.dueAt));

const overdue = computed<boolean>(
    () => props.card.completedAt === null && isOverdue(day.value),
);

const dueLabel = computed<string>(() =>
    day.value === null ? '' : formatDay(day.value),
);
</script>

<template>
    <article
        tabindex="0"
        data-task-card
        :data-task-id="card.id"
        :data-placement-id="card.placementId"
        class="group/card cursor-pointer rounded-md border border-border bg-card p-3 text-sm transition-shadow outline-none hover:shadow-xs focus-visible:ring-2 focus-visible:ring-primary-ring"
        :class="[
            card.completedAt ? 'text-muted-foreground' : '',
            dragging ? 'opacity-50' : '',
            editable ? 'touch-none active:cursor-grabbing' : '',
        ]"
        @pointerdown="down"
        @click="emit('open', card.id)"
        @keydown.enter.self="emit('open', card.id)"
    >
        <img
            v-if="card.cover"
            :src="
                AttachmentController.preview.url(card.cover.id, {
                    query: { size: 'thumb' },
                })
            "
            alt=""
            loading="lazy"
            decoding="async"
            draggable="false"
            class="mb-2 aspect-[16/9] w-full rounded-sm bg-muted object-cover"
        />

        <div v-if="card.tags.length" class="mb-2 flex flex-wrap gap-1">
            <span
                v-for="tag in card.tags"
                :key="tag.id"
                class="rounded px-1.5 py-0.5 text-[11px] font-medium"
                :class="accentChipClass(tag.color)"
                :style="accentVars(tag.color)"
            >
                {{ tag.name }}
            </span>
        </div>

        <!-- No click handler: the click bubbles to the card's own handler. -->
        <button
            type="button"
            class="line-clamp-3 w-full cursor-pointer text-left"
            :class="card.completedAt ? 'line-through' : ''"
        >
            {{ card.title }}
        </button>

        <div
            class="mt-2.5 flex items-center gap-2.5 text-xs text-muted-foreground"
        >
            <UserAvatar v-if="card.assignee" :user="card.assignee" size="sm" />
            <span
                v-else
                class="flex size-6 shrink-0 items-center justify-center rounded-md border border-dashed border-muted-foreground/40"
                title="Unassigned"
            >
                <UserRound
                    class="size-3.5 text-muted-foreground/60"
                    aria-hidden="true"
                />
                <span class="sr-only">Unassigned</span>
            </span>

            <span
                v-if="dueLabel"
                class="inline-flex items-center gap-1"
                :class="overdue ? 'text-destructive' : ''"
            >
                <TriangleAlert
                    v-if="overdue"
                    class="size-3.5"
                    aria-hidden="true"
                />
                {{ dueLabel }}
                <span v-if="overdue" class="sr-only">overdue</span>
            </span>

            <span
                v-if="card.subtasks > 0"
                class="inline-flex items-center gap-1"
            >
                <CheckSquare class="size-3.5" aria-hidden="true" />
                {{ card.subtasks }}
                <span class="sr-only">subtasks</span>
            </span>

            <span
                v-if="card.comments > 0"
                class="inline-flex items-center gap-1"
            >
                <MessageSquare class="size-3.5" aria-hidden="true" />
                {{ card.comments }}
                <span class="sr-only">comments</span>
            </span>

            <DropdownMenu v-if="editable">
                <DropdownMenuTrigger
                    class="ml-auto inline-flex size-6 items-center justify-center rounded-md opacity-0 transition-opacity group-focus-within/card:opacity-100 group-hover/card:opacity-100 hover:bg-accent hover:text-foreground focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    aria-label="Move to another column"
                    @click.stop
                    @pointerdown.stop
                >
                    <MoveRight class="size-3.5" />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        v-for="column in columns"
                        :key="keyOf(column)"
                        @select="
                            emit('moveto', card.placementId, keyOf(column))
                        "
                    >
                        {{ column.name ?? 'No section' }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </article>
</template>
