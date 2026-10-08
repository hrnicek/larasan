<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppTopbar from '@/components/AppTopbar.vue';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { TooltipProvider } from '@/components/ui/tooltip';
import { usePendingScreen } from '@/composables/usePendingScreen';
import { provideShell } from '@/composables/useShell';
import CommandPalette from '@/modules/search/components/CommandPalette.vue';
import { useCommandPalette } from '@/modules/search/composables/useCommandPalette';

const { mobileOpen } = provideShell();

const page = usePage();
const { skeleton, failed, retry } = usePendingScreen();

// Fade in only on a different screen or a replaced skeleton, never on a same-screen redraw after a write.
const screen = computed<string>(
    () =>
        `${page.component} ${new URL(page.url, window.location.origin).pathname}`,
);
const entering = ref(false);

watch([screen, skeleton], ([current, pending], [previous, wasPending]) => {
    if (current !== previous || (wasPending !== null && pending === null)) {
        entering.value = true;
    }
});

const settled = (event: AnimationEvent): void => {
    if (event.animationName === 'screen-in') {
        entering.value = false;
    }
};

const { handleShortcut } = useCommandPalette();

onMounted(() => document.addEventListener('keydown', handleShortcut));
onUnmounted(() => document.removeEventListener('keydown', handleShortcut));
</script>

<template>
    <TooltipProvider :delay-duration="200">
        <div class="flex h-svh w-full flex-col overflow-hidden bg-chrome">
            <AppTopbar />

            <div class="flex min-h-0 flex-1">
                <aside class="hidden shrink-0 md:block">
                    <AppSidebar />
                </aside>

                <Sheet v-model:open="mobileOpen">
                    <SheetContent
                        side="left"
                        class="w-72 border-chrome-border bg-chrome p-0 text-chrome-foreground"
                    >
                        <SheetTitle class="sr-only">Navigation</SheetTitle>
                        <AppSidebar variant="drawer" />
                    </SheetContent>
                </Sheet>

                <!--
                    `relative` keeps absolutely positioned `sr-only` descendants from growing the page past the shell.
                    `scroll-region` lets Inertia reset and restore scroll here, since the window itself never scrolls.
                -->
                <div
                    scroll-region
                    data-screen-canvas
                    :data-entering="entering ? '' : undefined"
                    class="relative min-w-0 flex-1 overflow-y-auto bg-background md:rounded-tl-xl md:border-t md:border-l md:border-border"
                    @animationend="settled"
                >
                    <div
                        v-if="failed"
                        class="mx-4 mt-4 flex items-center justify-between gap-4 rounded-lg border border-destructive/40 px-4 py-3 text-sm md:mx-6"
                        role="alert"
                    >
                        <span>This page did not load.</span>
                        <button
                            type="button"
                            class="font-medium underline"
                            @click="retry"
                        >
                            Try again
                        </button>
                    </div>

                    <div
                        v-if="skeleton"
                        data-screen-pending
                        class="flex flex-col"
                        aria-busy="true"
                    >
                        <p class="sr-only" role="status">Loading…</p>

                        <component :is="skeleton" />
                    </div>

                    <slot v-else />
                </div>
            </div>

            <CommandPalette />
        </div>
    </TooltipProvider>
</template>
