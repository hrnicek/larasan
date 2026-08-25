<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, ListFilter, X } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { accentDotClass } from '@/lib/accentColor';
import type { TaskTag } from '@/modules/task/types';
import { show } from '@/routes/projects';

/**
 * Narrowing a board or a list to what it is about.
 *
 * Every option is a real link, for the reason the view switcher's are: a filtered view is
 * something people send each other, so it has to be an address. Picking a second tag narrows
 * rather than widens — the server matches cards carrying *all* of them.
 *
 * A control rather than a row of every tag the workspace has: that row grows with the
 * vocabulary and pushes the content down for everybody, including the people filtering by
 * nothing. Once something is chosen it says so, with the count in the label, and carries its
 * own way out.
 */
const props = withDefaults(
    defineProps<{
        projectId: string;
        view: string;
        active: string[];
        available: TaskTag[];
        /** The calendar's month, carried through so filtering does not page back to this one. */
        month?: string;
    }>(),
    { month: undefined },
);

const count = computed<number>(() => props.active.length);

/** The URL this tag would lead to: on if it is off, off if it is on. */
const toggled = (tag: TaskTag): string => {
    const next = props.active.includes(tag.id)
        ? props.active.filter((id) => id !== tag.id)
        : [...props.active, tag.id];

    return show(props.projectId, { query: { view: props.view, month: props.month, tags: next } }).url;
};

const cleared = computed<string>(() => show(props.projectId, { query: { view: props.view, month: props.month } }).url);
</script>

<template>
    <div v-if="available.length" class="flex items-center">
        <DropdownMenu>
            <DropdownMenuTrigger
                class="inline-flex h-8 items-center gap-1.5 border px-2.5 text-[13px] font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                :class="
                    count > 0
                        ? 'rounded-l-md border-primary/40 bg-primary-subtle text-primary-subtle-foreground'
                        : 'rounded-md border-border text-muted-foreground hover:bg-accent hover:text-foreground'
                "
            >
                <ListFilter class="size-4" />
                <span>{{ count > 0 ? `Filter: ${count}` : 'Filter' }}</span>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="start" class="w-56">
                <DropdownMenuLabel class="text-xs text-muted-foreground">Tags</DropdownMenuLabel>

                <DropdownMenuItem v-for="tag in available" :key="tag.id" as-child class="gap-2">
                    <Link :href="toggled(tag)" :aria-pressed="active.includes(tag.id)" class="cursor-pointer">
                        <span class="size-2.5 shrink-0 rounded-[3px]" :class="accentDotClass(tag.color)" />
                        <span class="flex-1 truncate">{{ tag.name }}</span>
                        <Check v-if="active.includes(tag.id)" class="size-4 shrink-0 text-primary" />
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>

        <!-- Part of the chip rather than a separate "Clear" elsewhere: the way out belongs to the
             thing it undoes. -->
        <Link
            v-if="count > 0"
            :href="cleared"
            class="inline-flex h-8 items-center rounded-r-md border border-l-0 border-primary/40 bg-primary-subtle px-1.5 text-primary-subtle-foreground transition-colors hover:bg-primary/15 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
            aria-label="Clear the filter"
        >
            <X class="size-3.5" />
        </Link>
    </div>
</template>
