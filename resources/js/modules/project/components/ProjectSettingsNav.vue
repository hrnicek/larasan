<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { ProjectSettingsNavGroup } from '@/modules/project/types';

/**
 * The rail beside the project settings cards: where you are, and a way to the rest.
 *
 * The screen is one scrolling column rather than a set of sub-pages, because the fields on it
 * are read together — what a project is called, what it looks like and who can see it are one
 * errand. The rail keeps a long column navigable, mirrors the groups the column is divided into,
 * and reports the card the reader is actually at rather than merely the last one clicked.
 */
const props = defineProps<{ groups: ProjectSettingsNavGroup[] }>();

const items = computed(() => props.groups.flatMap((group) => group.items));

const active = ref<string>(items.value[0]?.id ?? '');

/** How far below the top of the canvas a card counts as the one being read. */
const threshold = 140;

function readActive(): void {
    let current = items.value[0]?.id ?? '';

    for (const item of items.value) {
        const element = document.getElementById(item.id);

        if (element && element.getBoundingClientRect().top <= threshold) {
            current = item.id;
        }
    }

    active.value = current;
}

/*
 * Measured rather than observed: the canvas is its own scrolling box inside the shell, and an
 * IntersectionObserver reports that box's cards a beat late — the rail then names the section
 * above the one on screen. Reading the rectangles on the frame after a scroll is exact.
 */
let frame = 0;

function schedule(): void {
    if (frame !== 0) {
        return;
    }

    frame = requestAnimationFrame(() => {
        frame = 0;
        readActive();
    });
}

onMounted(() => {
    // Capture, because the scroll happens in the shell's canvas and never reaches `window`.
    document.addEventListener('scroll', schedule, true);
    window.addEventListener('resize', schedule);
    readActive();
});

onBeforeUnmount(() => {
    document.removeEventListener('scroll', schedule, true);
    window.removeEventListener('resize', schedule);

    if (frame !== 0) {
        cancelAnimationFrame(frame);
    }
});

function go(id: string): void {
    const element = document.getElementById(id);

    if (!element) {
        return;
    }

    active.value = id;

    element.scrollIntoView({
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        block: 'start',
    });

    // The card takes the focus as well as the scroll, so the keyboard carries on from where the
    // eye was sent rather than from the rail it left.
    element.focus({ preventScroll: true });
}
</script>

<template>
    <nav
        class="flex flex-row gap-1 overflow-x-auto pb-1 [scrollbar-width:thin] lg:flex-col lg:gap-5 lg:overflow-x-visible lg:pb-0"
        aria-label="Project settings"
    >
        <!-- `shrink-0` on the group, not only on the items inside it. Below `lg` the rail is one
             scrolling row: a group that may shrink is squeezed narrower than the items it holds,
             and since those may not shrink, its last one is drawn over the next group's first. -->
        <div v-for="group in props.groups" :key="group.label" class="flex shrink-0 flex-row gap-1 lg:flex-col lg:gap-0.5">
            <!-- The group names are the column's own headings repeated; below `lg` the rail is a
                 single scrolling row and repeating them there would only cost reading width. -->
            <p class="hidden px-2.5 pb-1 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase lg:block">
                {{ group.label }}
            </p>

            <a
                v-for="item in group.items"
                :key="item.id"
                :href="`#${item.id}`"
                class="flex h-8 shrink-0 items-center gap-2 rounded-md px-2.5 text-[13px] font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                :class="
                    active === item.id
                        ? 'bg-muted text-foreground'
                        : 'text-muted-foreground hover:bg-accent hover:text-foreground'
                "
                :aria-current="active === item.id ? 'true' : undefined"
                @click.prevent="go(item.id)"
            >
                <component :is="item.icon" class="size-4" />
                {{ item.label }}
            </a>
        </div>
    </nav>
</template>
