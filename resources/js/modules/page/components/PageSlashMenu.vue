<script setup lang="ts">
import type { Component } from 'vue';

export type SlashCommand = {
    key: string;
    label: string;
    hint: string;
    icon: Component;
};

// A listbox rather than a menu: the editor keeps focus and points at the option via `aria-activedescendant`.
defineProps<{
    commands: SlashCommand[];
    selected: number;
    position: { top: number; left: number };
}>();

const emit = defineEmits<{ select: [command: SlashCommand] }>();
</script>

<template>
    <div
        class="fixed z-50 w-64 overflow-hidden rounded-lg border border-border bg-popover shadow-lg"
        :style="{ top: `${position.top}px`, left: `${position.left}px` }"
    >
        <ul id="page-slash-menu" role="listbox" aria-label="Insert" class="max-h-72 overflow-y-auto p-1">
            <li
                v-for="(command, index) in commands"
                :id="`page-slash-${command.key}`"
                :key="command.key"
                role="option"
                :aria-selected="index === selected"
                class="flex cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 text-sm"
                :class="index === selected ? 'bg-accent text-accent-foreground' : 'text-foreground'"
                @mousedown.prevent="emit('select', command)"
            >
                <span class="grid size-7 shrink-0 place-items-center rounded border border-border bg-background">
                    <component :is="command.icon" class="size-4 text-muted-foreground" aria-hidden="true" />
                </span>

                <span class="min-w-0">
                    <span class="block truncate font-medium">{{ command.label }}</span>
                    <span class="block truncate text-xs text-muted-foreground">{{ command.hint }}</span>
                </span>
            </li>

            <li v-if="!commands.length" class="px-2 py-1.5 text-sm text-muted-foreground">
                Nothing matches that.
            </li>
        </ul>
    </div>
</template>
