<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppTopbar from '@/components/AppTopbar.vue';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { TooltipProvider } from '@/components/ui/tooltip';
import { provideShell } from '@/composables/useShell';
import CommandPalette from '@/modules/search/components/CommandPalette.vue';
import { useCommandPalette } from '@/modules/search/composables/useCommandPalette';

const { mobileOpen } = provideShell();

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
                -->
                <div
                    class="relative min-w-0 flex-1 overflow-y-auto bg-background md:rounded-tl-xl md:border-t md:border-l md:border-border"
                >
                    <slot />
                </div>
            </div>

            <CommandPalette />
        </div>
    </TooltipProvider>
</template>
