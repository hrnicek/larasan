<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { ProjectSettingsNavGroup } from '@/modules/project/types';

const props = defineProps<{ groups: ProjectSettingsNavGroup[] }>();

const items = computed(() => props.groups.flatMap((group) => group.items));

const active = ref<string>(items.value[0]?.id ?? '');

/** Pixels from the top of the viewport at which a card becomes the active one. */
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

// Rects are read once per frame; an IntersectionObserver lags inside the shell's scroll container.
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
    // Capture phase: the shell's canvas scrolls, and scroll events do not bubble to `window`.
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

    element.focus({ preventScroll: true });
}
</script>

<template>
    <nav
        class="flex flex-row gap-1 overflow-x-auto pb-1 [scrollbar-width:thin] lg:flex-col lg:gap-5 lg:overflow-x-visible lg:pb-0"
        aria-label="Project settings"
    >
        <!-- The group needs `shrink-0` too, or below `lg` its items overflow onto the next group. -->
        <div v-for="group in props.groups" :key="group.label" class="flex shrink-0 flex-row gap-1 lg:flex-col lg:gap-0.5">
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
