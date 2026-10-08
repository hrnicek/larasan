<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, UserPlus, X } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import UserAvatar from '@/components/UserAvatar.vue';
import type { TaskAssignee } from '@/modules/task/types';

const props = defineProps<{
    taskId: string;
    assignee: TaskAssignee | null;
    members: TaskAssignee[];
    editable: boolean;
    variant?: 'inline' | 'field';
}>();

const open = ref(false);
const saving = ref(false);
const query = ref('');
const highlighted = ref(0);
const input = ref<HTMLInputElement | null>(null);
const listId = 'assignee-options';

/** Case- and accent-insensitive, the same rule as server-side search. See ADR-0012. */
const flatten = (value: string): string =>
    value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();

const matches = computed<TaskAssignee[]>(() => {
    const term = flatten(query.value.trim());

    if (term === '') {
        return props.members;
    }

    return props.members.filter(
        (member) =>
            flatten(member.name).includes(term) ||
            flatten(member.email ?? '').includes(term),
    );
});

watch(matches, () => (highlighted.value = 0));

watch(open, (isOpen) => {
    if (!isOpen) {
        query.value = '';

        return;
    }

    highlighted.value = 0;
    void nextTick(() => input.value?.focus());
});

function assign(id: number | null, onDone?: () => void): void {
    saving.value = true;

    router.put(
        TaskController.assign.url(props.taskId),
        { assignee_id: id },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = false;
                open.value = false;
                onDone?.();
            },
        },
    );
}

function unassign(): void {
    const previous = props.assignee;

    assign(null, () => {
        if (previous === null) {
            return;
        }

        toast(`${previous.name} removed from this task.`, {
            action: { label: 'Undo', onClick: () => assign(previous.id) },
        });
    });
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        highlighted.value =
            (highlighted.value + 1) % Math.max(matches.value.length, 1);

        return;
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        highlighted.value =
            (highlighted.value - 1 + matches.value.length) %
            Math.max(matches.value.length, 1);

        return;
    }

    if (event.key === 'Enter') {
        event.preventDefault();

        const chosen = matches.value[highlighted.value];

        if (chosen !== undefined) {
            assign(chosen.id);
        }
    }
}
</script>

<template>
    <span v-if="!editable" class="text-xs text-muted-foreground">
        {{ assignee?.name ?? '—' }}
    </span>

    <div v-else class="flex items-center gap-1">
        <Popover v-model:open="open">
            <PopoverTrigger
                :disabled="saving"
                :aria-label="
                    assignee
                        ? `Assigned to ${assignee.name}. Change assignee`
                        : 'Unassigned. Assign someone'
                "
                :title="
                    variant === 'field'
                        ? undefined
                        : (assignee?.name ?? 'Unassigned')
                "
                class="flex min-h-11 items-center gap-1.5 rounded-md text-left transition-colors focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none disabled:opacity-50 md:min-h-8"
                :class="
                    variant === 'field'
                        ? 'h-8 border border-border px-1.5 pr-2 text-sm hover:bg-accent'
                        : 'px-1 text-xs text-muted-foreground hover:text-foreground'
                "
            >
                <template v-if="assignee">
                    <UserAvatar :user="assignee" size="sm" />
                    <span v-if="variant === 'field'" class="truncate">{{
                        assignee.name
                    }}</span>
                </template>

                <template v-else>
                    <span
                        class="flex size-6 shrink-0 items-center justify-center rounded-md border border-dashed border-muted-foreground/50 text-muted-foreground"
                    >
                        <UserPlus class="size-3.5" />
                    </span>
                    <span
                        v-if="variant === 'field'"
                        class="text-muted-foreground"
                        >Unassigned</span
                    >
                </template>
            </PopoverTrigger>

            <PopoverContent class="w-72 p-0">
                <div class="border-b border-border p-2">
                    <input
                        ref="input"
                        v-model="query"
                        type="text"
                        role="combobox"
                        aria-expanded="true"
                        :aria-controls="listId"
                        aria-label="Search members by name or email"
                        placeholder="Name or email"
                        class="h-8 w-full rounded-md bg-transparent px-2 text-sm outline-none placeholder:text-muted-foreground"
                        @keydown="onKeydown"
                    />
                </div>

                <ul
                    :id="listId"
                    role="listbox"
                    class="max-h-64 overflow-y-auto p-1"
                >
                    <li
                        v-for="(member, index) in matches"
                        :key="member.id"
                        role="option"
                        :aria-selected="member.id === assignee?.id"
                        class="relative"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center gap-2 rounded-md py-1.5 pr-2 pl-3 text-left transition-colors"
                            :class="
                                index === highlighted
                                    ? 'bg-accent'
                                    : 'hover:bg-accent/60'
                            "
                            @click="assign(member.id)"
                            @mousemove="highlighted = index"
                        >
                            <span
                                v-if="index === highlighted"
                                class="absolute inset-y-1 left-0 w-0.5 rounded-full bg-primary"
                                aria-hidden="true"
                            />
                            <UserAvatar :user="member" size="sm" />
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-medium"
                                    >{{ member.name }}</span
                                >
                                <span
                                    v-if="member.email"
                                    class="block truncate text-xs text-muted-foreground"
                                    >{{ member.email }}</span
                                >
                            </span>
                            <Check
                                v-if="member.id === assignee?.id"
                                class="size-4 shrink-0 text-primary"
                            />
                        </button>
                    </li>

                    <li
                        v-if="matches.length === 0"
                        class="px-3 py-6 text-center text-sm text-muted-foreground"
                    >
                        Nobody here matches “{{ query }}”.
                    </li>
                </ul>
            </PopoverContent>
        </Popover>

        <button
            v-if="assignee && variant === 'field'"
            type="button"
            class="inline-flex size-6 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
            :disabled="saving"
            :aria-label="`Remove ${assignee.name} from this task`"
            @click="unassign"
        >
            <X class="size-3.5" />
        </button>
    </div>
</template>
