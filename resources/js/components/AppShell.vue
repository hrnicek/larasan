<script setup lang="ts">
import AppSidebar from '@/components/AppSidebar.vue';
import AppTopbar from '@/components/AppTopbar.vue';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { TooltipProvider } from '@/components/ui/tooltip';
import { provideShell } from '@/composables/useShell';

const { mobileOpen } = provideShell();
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

                <div class="min-w-0 flex-1 overflow-y-auto bg-background md:rounded-tl-xl md:border-t md:border-l md:border-border">
                    <slot />
                </div>
            </div>
        </div>
    </TooltipProvider>
</template>
