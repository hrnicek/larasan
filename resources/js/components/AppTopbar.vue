<script setup lang="ts">
import { PanelLeft, Search as SearchIcon } from '@lucide/vue';
import CreateMenu from '@/components/CreateMenu.vue';
import { useShell } from '@/composables/useShell';
import { useCommandPalette } from '@/modules/search/composables/useCommandPalette';

const { toggle, openMobile } = useShell();

const { show: openSearch } = useCommandPalette();
</script>

<template>
    <header class="flex h-13 shrink-0 items-center gap-2 border-b border-chrome-border bg-chrome px-2 text-chrome-foreground sm:px-3">
        <button
            type="button"
            class="hidden size-8 shrink-0 items-center justify-center rounded-md text-chrome-muted-foreground transition-colors hover:bg-chrome-accent hover:text-chrome-foreground focus-visible:ring-2 focus-visible:ring-chrome-primary focus-visible:outline-none md:inline-flex"
            aria-label="Toggle sidebar"
            @click="toggle"
        >
            <PanelLeft class="size-4" />
        </button>

        <button
            type="button"
            class="inline-flex size-11 shrink-0 items-center justify-center rounded-md text-chrome-muted-foreground transition-colors hover:bg-chrome-accent hover:text-chrome-foreground focus-visible:ring-2 focus-visible:ring-chrome-primary focus-visible:outline-none md:hidden"
            aria-label="Open navigation"
            @click="openMobile"
        >
            <PanelLeft class="size-4" />
        </button>

        <CreateMenu />

        <button
            type="button"
            class="mx-auto flex h-8 w-full max-w-md items-center gap-2 rounded-md border border-chrome-border bg-chrome-accent/60 px-2.5 text-[13px] text-chrome-muted-foreground transition-colors hover:bg-chrome-accent hover:text-chrome-foreground focus-visible:ring-2 focus-visible:ring-chrome-primary focus-visible:outline-none"
            @click="openSearch()"
        >
            <SearchIcon class="size-4 shrink-0" />
            <span class="truncate">Search</span>
            <kbd class="ml-auto hidden shrink-0 rounded border border-chrome-border px-1.5 py-0.5 font-sans text-[10px] tracking-wide sm:inline">⌘K</kbd>
        </button>
    </header>
</template>
