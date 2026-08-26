<script setup lang="ts">
import { computed } from 'vue';
import { accentChipClass } from '@/lib/accentColor';
import type { TaskTag } from '@/modules/task/types';

/**
 * A task's tags where there is one line to say them in.
 *
 * A list row's name cell carries the title, the tags and the controls that open the task, and the
 * title is the part being read. The chips were `shrink-0`, so a task with five of them took the
 * cell from the title rather than the other way round: the title collapsed to nothing and the
 * chips were drawn over what was left of it.
 *
 * So the cell is shared by a rule rather than by whoever reaches it first. The title yields to a
 * floor and no further (`TaskTextField`); the chips do not yield at all, because a tag shortened
 * to `d…` says less than nothing; and how many are drawn is decided by how much room the cell has.
 *
 * **The cell, not the window.** A project with four custom field columns leaves the name a third
 * of the width the same screen gives a project with none, so a `lg:` breakpoint would be reading
 * the wrong number. The name cell declares itself a container and these chips answer to its width:
 * none at all below 20rem, where a chip could only be drawn over the title; one up to 32rem; two
 * beyond it; and in both of the last two the remainder as a count.
 *
 * The count is a real answer rather than a loss — this is a list somebody is scanning, every tag
 * is on the task, and the ones it stands for are named in its tooltip. Both counts are in the
 * markup with one of them hidden, because which is right depends on how many chips are beside it,
 * and CSS is what knows the cell's width.
 *
 * The board's card wraps its tags onto a line of their own instead (`TaskCard`), because there it
 * has one.
 */
const props = defineProps<{ tags: TaskTag[] }>();

/** The most that are ever drawn; the second appears only where the cell is wide enough for it. */
const shown = computed(() => props.tags.slice(0, 2));

const hiddenBesideOne = computed(() => props.tags.slice(1));
const hiddenBesideTwo = computed(() => props.tags.slice(2));

const named = (tags: TaskTag[]): string => tags.map((tag) => tag.name).join(', ');
</script>

<template>
    <span v-if="tags.length" class="hidden shrink-0 items-center gap-1 text-[11px] font-medium @xs:flex">
        <span
            v-for="(tag, position) in shown"
            :key="tag.id"
            class="max-w-20 shrink-0 truncate rounded px-1.5 py-0.5"
            :class="[accentChipClass(tag.color), position > 0 ? 'hidden @lg:block' : '']"
            :title="tag.name"
        >
            {{ tag.name }}
        </span>

        <span
            v-if="hiddenBesideOne.length"
            class="shrink-0 rounded bg-muted px-1.5 py-0.5 text-muted-foreground @lg:hidden"
            :title="named(hiddenBesideOne)"
        >
            +{{ hiddenBesideOne.length }}
        </span>

        <span
            v-if="hiddenBesideTwo.length"
            class="hidden shrink-0 rounded bg-muted px-1.5 py-0.5 text-muted-foreground @lg:block"
            :title="named(hiddenBesideTwo)"
        >
            +{{ hiddenBesideTwo.length }}
        </span>
    </span>
</template>
