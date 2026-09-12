<script setup lang="ts">
import { computed, nextTick, ref, useId } from 'vue';
import UserAvatar from '@/components/UserAvatar.vue';
import { foldForSearch } from '@/modules/comment/mentions';
import type { NamedPerson } from '@/modules/comment/mentions';
import type { TaskAssignee } from '@/modules/task/types';

// Callers listen for `submit` and `cancel` rather than binding Enter and Escape, so Escape closing
// the list does not also abandon an edit.
defineOptions({ inheritAttrs: false });

const props = defineProps<{ people: TaskAssignee[] }>();
const text = defineModel<string>({ required: true });
const named = defineModel<NamedPerson[]>('named', { required: true });
const emit = defineEmits<{ submit: []; cancel: [] }>();

const SUGGESTIONS = 8;

const field = ref<HTMLTextAreaElement | null>(null);
const query = ref<string | null>(null);
const start = ref(0);
const highlighted = ref(0);
const listId = useId();

const suggestions = computed<TaskAssignee[]>(() => {
    if (query.value === null) {
        return [];
    }

    const needle = foldForSearch(query.value.trim());

    return props.people
        .filter((person) => {
            const name = foldForSearch(person.name);

            return (
                needle === '' ||
                name.startsWith(needle) ||
                name.includes(` ${needle}`) ||
                foldForSearch(person.email).startsWith(needle)
            );
        })
        .slice(0, SUGGESTIONS);
});

const open = computed<boolean>(() => suggestions.value.length > 0);

const close = (): void => {
    query.value = null;
};

// The text is read from the element, never the model: inside an input event `defineModel` is one
// keystroke behind. Up to two words after the `@` are matched, for full names.
const detect = (): void => {
    const element = field.value;

    if (element === null || element.selectionStart !== element.selectionEnd) {
        close();

        return;
    }

    const caret = element.selectionStart;
    const match = /(?:^|\s)@([^\s@]{0,30}(?: [^\s@]{0,30})?)$/u.exec(
        element.value.slice(0, caret),
    );

    if (match === null) {
        close();

        return;
    }

    query.value = match[1];
    start.value = caret - match[1].length - 1;
    highlighted.value = 0;
};

const choose = (person: TaskAssignee): void => {
    const element = field.value;

    if (element === null) {
        return;
    }

    const current = element.value;
    const inserted = `@${person.name} `;
    const position = start.value + inserted.length;
    const next =
        current.slice(0, start.value) +
        inserted +
        current.slice(element.selectionStart);

    element.value = next;
    element.setSelectionRange(position, position);
    text.value = next;

    if (!named.value.some((entry) => entry.id === person.id)) {
        named.value = [...named.value, { id: person.id, name: person.name }];
    }

    close();

    void nextTick(() => {
        element.focus();
        element.setSelectionRange(position, position);
    });
};

const onKeydown = (event: KeyboardEvent): void => {
    if (open.value) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();

            const step = event.key === 'ArrowDown' ? 1 : -1;
            highlighted.value =
                (highlighted.value + step + suggestions.value.length) %
                suggestions.value.length;

            return;
        }

        const person = suggestions.value[highlighted.value];

        if (
            person !== undefined &&
            (event.key === 'Tab' ||
                (event.key === 'Enter' && !event.metaKey && !event.ctrlKey))
        ) {
            event.preventDefault();
            choose(person);

            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close();

            return;
        }
    }

    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        emit('submit');

        return;
    }

    if (event.key === 'Escape') {
        emit('cancel');
    }
};

const onKeyup = (event: KeyboardEvent): void => {
    if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
        detect();
    }
};
</script>

<template>
    <div class="relative">
        <textarea
            ref="field"
            v-bind="$attrs"
            v-model="text"
            role="combobox"
            aria-autocomplete="list"
            :aria-expanded="open"
            :aria-controls="open ? listId : undefined"
            :aria-activedescendant="
                open ? `${listId}-${highlighted}` : undefined
            "
            @input="detect"
            @click="detect"
            @keydown="onKeydown"
            @keyup="onKeyup"
            @blur="close"
        />

        <ul
            v-if="open"
            :id="listId"
            role="listbox"
            aria-label="People to mention"
            class="absolute bottom-full left-0 z-30 mb-1 max-h-64 w-72 max-w-full overflow-y-auto rounded-md border border-border bg-popover p-1 text-popover-foreground shadow-md"
        >
            <li
                v-for="(person, index) in suggestions"
                :id="`${listId}-${index}`"
                :key="person.id"
                role="option"
                :aria-selected="index === highlighted"
                class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm"
                :class="
                    index === highlighted
                        ? 'bg-accent text-accent-foreground'
                        : ''
                "
                @mousedown.prevent="choose(person)"
                @mousemove="highlighted = index"
            >
                <UserAvatar
                    :user="{ name: person.name, avatar: person.avatar }"
                    size="sm"
                />
                <span class="min-w-0 flex-1 truncate">{{ person.name }}</span>
                <span
                    class="max-w-[45%] truncate text-xs text-muted-foreground"
                    >{{ person.email }}</span
                >
            </li>
        </ul>
    </div>
</template>
