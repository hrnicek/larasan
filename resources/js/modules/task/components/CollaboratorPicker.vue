<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Check, UserPlus } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import TaskCollaboratorController from '@/actions/App/Http/Controllers/Task/TaskCollaboratorController';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import UserAvatar from '@/components/UserAvatar.vue';
import type { TaskAssignee } from '@/modules/task/types';

const props = defineProps<{
    taskId: string;
    collaborators: TaskAssignee[];
    assigneeId: number | null;
    members: TaskAssignee[];
    editable: boolean;
    collaborating: boolean;
}>();

const open = ref(false);
const saving = ref(false);
const query = ref('');
const highlighted = ref(0);
const input = ref<HTMLInputElement | null>(null);
const listId = 'collaborator-options';

const viewerId = computed<number | null>(
    () => usePage().props.auth.user?.id ?? null,
);

const shown = computed<TaskAssignee[]>(() => props.collaborators.slice(0, 4));
const hidden = computed<number>(() =>
    Math.max(props.collaborators.length - shown.value.length, 0),
);
const onTask = computed<Set<number>>(
    () => new Set(props.collaborators.map((person) => person.id)),
);

const summary = computed<string>(() =>
    props.collaborators.length === 1
        ? props.collaborators[0].name
        : `${props.collaborators.length} people`,
);

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
            flatten(member.email).includes(term),
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

/** `preserveState` keeps the popover open between toggles. */
const visit = {
    preserveScroll: true,
    preserveState: true,
    onFinish: () => {
        saving.value = false;
    },
};

function remove(id: number): void {
    saving.value = true;
    router.delete(
        TaskCollaboratorController.destroy.url({
            task: props.taskId,
            collaborator: id,
        }),
        visit,
    );
}

function toggle(member: TaskAssignee): void {
    if (member.id === props.assigneeId || saving.value) {
        return;
    }

    if (onTask.value.has(member.id)) {
        remove(member.id);

        return;
    }

    saving.value = true;
    router.post(
        TaskCollaboratorController.store.url(props.taskId),
        { user_id: member.id },
        visit,
    );
}

function leave(): void {
    if (viewerId.value !== null) {
        remove(viewerId.value);
    }
}

function onKeydown(event: KeyboardEvent): void {
    const count = Math.max(matches.value.length, 1);

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        highlighted.value = (highlighted.value + 1) % count;

        return;
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        highlighted.value = (highlighted.value - 1 + count) % count;

        return;
    }

    if (event.key === 'Enter') {
        event.preventDefault();

        const member = matches.value[highlighted.value];

        if (member !== undefined) {
            toggle(member);
        }
    }
}

/** Closes only the popover, not a dialog that may contain it. */
function onEscape(event: KeyboardEvent): void {
    event.preventDefault();
    open.value = false;
}
</script>

<template>
    <div class="flex min-w-0 items-center gap-2">
        <Popover v-if="editable" v-model:open="open">
            <PopoverTrigger
                :aria-label="
                    collaborators.length
                        ? `Collaborators: ${collaborators.map((person) => person.name).join(', ')}. Change`
                        : 'No collaborators. Add people'
                "
                class="flex h-8 min-h-11 min-w-0 items-center gap-1.5 rounded-md border border-border px-1.5 pr-2 text-left text-sm transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none md:min-h-8"
            >
                <template v-if="collaborators.length">
                    <span class="flex shrink-0 -space-x-1.5">
                        <span
                            v-for="person in shown"
                            :key="person.id"
                            class="rounded-md ring-2 ring-background"
                        >
                            <UserAvatar :user="person" size="sm" />
                        </span>
                        <span
                            v-if="hidden"
                            class="flex size-6 items-center justify-center rounded-md bg-muted text-[10px] font-semibold text-muted-foreground ring-2 ring-background"
                        >
                            +{{ hidden }}
                        </span>
                    </span>
                    <span class="truncate">{{ summary }}</span>
                </template>

                <template v-else>
                    <span
                        class="flex size-6 shrink-0 items-center justify-center rounded-md border border-dashed border-muted-foreground/50 text-muted-foreground"
                    >
                        <UserPlus class="size-3.5" />
                    </span>
                    <span class="text-muted-foreground">Add people</span>
                </template>
            </PopoverTrigger>

            <PopoverContent class="w-72 p-0" @escape-key-down="onEscape">
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
                    aria-multiselectable="true"
                    class="max-h-64 overflow-y-auto p-1"
                >
                    <li
                        v-for="(member, index) in matches"
                        :key="member.id"
                        role="option"
                        :aria-selected="onTask.has(member.id)"
                        :aria-disabled="member.id === assigneeId"
                        class="relative"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center gap-2 rounded-md py-1.5 pr-2 pl-3 text-left transition-colors disabled:cursor-default disabled:opacity-60"
                            :class="
                                index === highlighted
                                    ? 'bg-accent'
                                    : 'hover:bg-accent/60'
                            "
                            :disabled="member.id === assigneeId || saving"
                            @click="toggle(member)"
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
                                    class="block truncate text-xs text-muted-foreground"
                                    >{{ member.email }}</span
                                >
                            </span>
                            <span
                                v-if="member.id === assigneeId"
                                class="shrink-0 text-xs text-muted-foreground"
                                >Assignee</span
                            >
                            <Check
                                v-else-if="onTask.has(member.id)"
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

        <template v-else>
            <span
                v-if="collaborators.length === 0"
                class="px-1.5 text-sm text-muted-foreground"
                >—</span
            >

            <span v-else class="flex items-center gap-1.5 px-1.5">
                <span class="flex shrink-0 -space-x-1.5">
                    <span
                        v-for="person in shown"
                        :key="person.id"
                        class="rounded-md ring-2 ring-background"
                        :title="person.name"
                    >
                        <UserAvatar :user="person" size="sm" />
                    </span>
                </span>
                <span class="truncate text-sm">{{ summary }}</span>
            </span>
        </template>

        <!-- A collaborator without edit rights may still remove themselves. -->
        <button
            v-if="!editable && collaborating"
            type="button"
            class="shrink-0 rounded-md px-1.5 py-1 text-xs text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
            :disabled="saving"
            @click="leave"
        >
            Leave
        </button>
    </div>
</template>
