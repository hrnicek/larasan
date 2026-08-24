<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { PanelLeft, Search as SearchIcon } from '@lucide/vue';
import { computed } from 'vue';
import SearchController from '@/actions/App/Http/Controllers/Search/SearchController';
import CreateMenu from '@/components/CreateMenu.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import UserAvatar from '@/components/UserAvatar.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useShell } from '@/composables/useShell';

const page = usePage();
const { toggle, openMobile } = useShell();

const user = computed(() => page.props.auth.user);

/*
 * The field is a button rather than an input. Search is a screen with its own address and its own
 * filters (ADR-0012), so typing here would mean typing into a control that navigates away from
 * itself on the first keystroke.
 */
function openSearch(): void {
    router.get(SearchController.index.url());
}
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
            @click="openSearch"
        >
            <SearchIcon class="size-4 shrink-0" />
            <span class="truncate">Search</span>
            <kbd class="ml-auto hidden shrink-0 rounded border border-chrome-border px-1.5 py-0.5 font-sans text-[10px] tracking-wide sm:inline">⌘K</kbd>
        </button>

        <DropdownMenu v-if="user">
            <DropdownMenuTrigger
                class="shrink-0 rounded-md focus-visible:ring-2 focus-visible:ring-chrome-primary focus-visible:ring-offset-2 focus-visible:ring-offset-chrome focus-visible:outline-none"
                :aria-label="`Account menu for ${user.name}`"
            >
                <UserAvatar :user="user" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="min-w-56">
                <UserMenuContent :user="user" />
            </DropdownMenuContent>
        </DropdownMenu>
    </header>
</template>
