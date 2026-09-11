<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import type { Component } from 'vue';
import { computed } from 'vue';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useCollapsed } from '@/composables/useShell';

const props = defineProps<{
    href: string;
    label: string;
    icon?: Component;
    active?: boolean;
    badge?: number;
    /**
     * The page component the link opens. Named, the visit is instant — the screen is drawn at
     * once, as its skeleton until its own props land (`usePendingScreen`) — and the request leaves
     * on press rather than on release. Never for the screen already open: that would blank it only
     * to draw what is there again.
     */
    component?: string;
}>();

const collapsed = useCollapsed();

const instant = computed<string | undefined>(() => (props.active ? undefined : props.component));

/* The page's own chunk, fetched while the pointer is still on its way to a press. */
const warm = (): void => {
    if (instant.value !== undefined) {
        void router.resolveComponent(instant.value);
    }
};
</script>

<template>
    <Tooltip :disable-hoverable-content="!collapsed">
        <TooltipTrigger as-child>
            <Link
                :href="href"
                :component="instant"
                :prefetch="instant === undefined ? false : 'click'"
                :aria-current="active ? 'page' : undefined"
                class="group/nav flex h-9 items-center gap-2.5 rounded-md px-2 text-[13px] font-medium text-chrome-muted-foreground transition-colors hover:bg-chrome-accent hover:text-chrome-foreground focus-visible:ring-2 focus-visible:ring-chrome-primary focus-visible:outline-none"
                :class="[active && 'bg-chrome-accent text-chrome-foreground', collapsed && 'justify-center px-0']"
                @pointerenter="warm"
                @focus="warm"
            >
                <slot name="icon">
                    <component :is="icon" v-if="icon" class="size-4 shrink-0" />
                </slot>

                <span v-if="!collapsed" class="truncate">{{ label }}</span>
                <span v-else class="sr-only">{{ label }}</span>

                <!--
                    The count is a number and a word, never a coloured dot on its own: a badge that
                    says only "something" is a badge nobody can act on.
                -->
                <span
                    v-if="badge"
                    class="ml-auto inline-flex min-w-5 shrink-0 items-center justify-center rounded-full bg-chrome-primary px-1.5 text-[11px] font-semibold text-chrome-primary-foreground"
                    :class="collapsed && 'absolute top-1 right-1 ml-0'"
                >
                    {{ badge > 99 ? '99+' : badge }}
                    <span class="sr-only">unread</span>
                </span>
            </Link>
        </TooltipTrigger>

        <TooltipContent v-if="collapsed" side="right">{{ label }}</TooltipContent>
    </Tooltip>
</template>
