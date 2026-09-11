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

/*
 * A screen fades in when it is a different screen, or when it replaces its own skeleton — never
 * when the same screen is drawn again after a write, which would read as the page blinking. The
 * flag is raised before the render that inserts the new page, so the animation in `app.css` is on
 * the element from its first frame, and lowered when that animation ends.
 */
const screen = computed<string>(() => `${page.component} ${new URL(page.url, window.location.origin).pathname}`);
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

/*
 * The palette is drawn once, here: it opens over any screen, and one listener on the document is
 * what makes `⌘K` mean the same thing everywhere rather than only where somebody remembered to
 * add it.
 */
const { handleShortcut } = useCommandPalette();

onMounted(() => document.addEventListener('keydown', handleShortcut));
onUnmounted(() => document.removeEventListener('keydown', handleShortcut));
</script>

<!--
  The chrome frames the canvas: a topbar across the full width, the sidebar under it on the left,
  and the page in what is left (ADR-0014). Below `md` the sidebar is a drawer instead, because a
  64px-wide rail beside a phone-width canvas leaves neither of them usable.
-->
<template>
    <TooltipProvider :delay-duration="200">
        <div class="flex h-svh w-full flex-col overflow-hidden bg-chrome">
            <AppTopbar />

            <div class="flex min-h-0 flex-1">
                <aside class="hidden shrink-0 md:block">
                    <AppSidebar />
                </aside>

                <Sheet v-model:open="mobileOpen">
                    <SheetContent side="left" class="w-72 border-chrome-border bg-chrome p-0 text-chrome-foreground">
                        <SheetTitle class="sr-only">Navigation</SheetTitle>
                        <AppSidebar variant="drawer" />
                    </SheetContent>
                </Sheet>

                <!--
                    `relative` is load-bearing: `sr-only` is `position: absolute`, and a visually
                    hidden label with no positioned ancestor is laid out against the document at
                    its static position — far down the canvas — which grows the page past the
                    shell's own height and lets the whole application scroll out of the window.
                    Positioning the canvas keeps every absolute descendant inside the box that
                    scrolls and clips.

                    `scroll-region` hands the canvas to Inertia: a new screen starts at its top,
                    and back or forward returns to where the reader was. The window never scrolls
                    in this shell, so without it every screen opened at the last one's depth.
                -->
                <div
                    scroll-region
                    data-screen-canvas
                    :data-entering="entering ? '' : undefined"
                    class="relative min-w-0 flex-1 overflow-y-auto bg-background md:rounded-tl-xl md:border-t md:border-l md:border-border"
                    @animationend="settled"
                >
                    <!--
                        A screen opened instantly is drawn as its skeleton until its own props
                        land (`usePendingScreen`).
                    -->
                    <div v-if="skeleton" data-screen-pending class="flex flex-col" aria-busy="true">
                        <p class="sr-only" role="status">Loading…</p>

                        <div
                            v-if="failed"
                            class="mx-4 mt-4 flex items-center justify-between gap-4 rounded-lg border border-destructive/40 px-4 py-3 text-sm md:mx-6"
                            role="alert"
                        >
                            <span>This page did not load.</span>
                            <button type="button" class="font-medium underline" @click="retry">Try again</button>
                        </div>

                        <component :is="skeleton" />
                    </div>

                    <slot v-else />
                </div>
            </div>

            <CommandPalette />
        </div>
    </TooltipProvider>
</template>
