<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { MoreHorizontal, Settings } from '@lucide/vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { accentTileClass } from '@/lib/accentColor';
import ViewSwitcher from '@/modules/project/components/ViewSwitcher.vue';
import { edit } from '@/routes/projects';

/**
 * The project's own header: what this is, and which way you are looking at it.
 *
 * The name is drawn in the foreground colour, not in the project's accent. The accent identifies
 * the project at a glance in a list of them — as a tile here and a dot in the sidebar — and a
 * heading tinted the same way is a heading whose contrast depends on which colour somebody picked.
 */
defineProps<{
    project: { id: string; name: string; color: string | null; archived: boolean };
    view: string;
    views: string[];
}>();
</script>

<template>
    <header class="border-b border-border">
        <div class="flex flex-wrap items-center gap-3 px-4 pt-4 pb-3 md:px-6">
            <span
                class="flex size-9 shrink-0 items-center justify-center rounded-lg text-sm font-bold"
                :class="accentTileClass(project.color)"
                aria-hidden="true"
            >
                {{ project.name.charAt(0).toUpperCase() }}
            </span>

            <h1 class="min-w-0 truncate text-xl font-semibold tracking-tight">{{ project.name }}</h1>

            <span v-if="project.archived" class="rounded-md bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                Archived
            </span>

            <DropdownMenu>
                <DropdownMenuTrigger
                    class="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    :aria-label="`Actions for ${project.name}`"
                >
                    <MoreHorizontal class="size-4" />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" class="w-48">
                    <DropdownMenuItem as-child>
                        <Link :href="edit(project.id).url" class="block w-full cursor-pointer">
                            <Settings class="mr-2 size-4 text-muted-foreground" />
                            Project settings
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <div class="px-2 md:px-4">
            <ViewSwitcher :project-id="project.id" :current="view" :views="views" />
        </div>
    </header>
</template>
