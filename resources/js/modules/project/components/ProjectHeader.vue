<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { MoreHorizontal, Settings, Star, StarOff } from '@lucide/vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import ProjectAppearancePicker from '@/modules/project/components/ProjectAppearancePicker.vue';
import ProjectCustomizeSheet from '@/modules/project/components/ProjectCustomizeSheet.vue';
import ProjectMemberFaces from '@/modules/project/components/ProjectMemberFaces.vue';
import ProjectShareDialog from '@/modules/project/components/ProjectShareDialog.vue';
import ProjectTile from '@/modules/project/components/ProjectTile.vue';
import ViewSwitcher from '@/modules/project/components/ViewSwitcher.vue';
import type { ProjectCustomize, ProjectHeading, ProjectShare } from '@/modules/project/types';
import { edit, star, unstar } from '@/routes/projects';

const props = defineProps<{
    project: ProjectHeading;
    view: string;
    views: string[];
    /** Optional prop, undefined until the drawer loads it. */
    customize?: ProjectCustomize;
    /** Optional prop, undefined until the dialog loads it. */
    share?: ProjectShare;
}>();

function toggleStar(): void {
    if (props.project.starred) {
        router.delete(unstar(props.project.id).url, { preserveScroll: true });

        return;
    }

    router.post(star(props.project.id).url, {}, { preserveScroll: true });
}
</script>

<template>
    <header class="border-b border-border">
        <div class="flex flex-wrap items-center gap-3 px-4 pt-4 pb-3 md:px-6">
            <ProjectAppearancePicker v-if="props.project.canUpdate" :project="props.project" />
            <ProjectTile
                v-else
                :name="props.project.name"
                :color="props.project.color"
                :icon="props.project.icon"
                size="lg"
            />

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
                <DropdownMenuContent align="start" class="w-56">
                    <DropdownMenuItem @select="toggleStar">
                        <component :is="project.starred ? StarOff : Star" class="mr-2 size-4 text-muted-foreground" />
                        {{ project.starred ? 'Remove from starred' : 'Add to starred' }}
                    </DropdownMenuItem>

                    <DropdownMenuItem as-child>
                        <Link :href="edit(project.id).url" component="projects/Settings" class="block w-full cursor-pointer">
                            <Settings class="mr-2 size-4 text-muted-foreground" />
                            Project settings
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <div class="ml-auto flex shrink-0 items-center gap-3">
                <ProjectMemberFaces :members="project.members" :total="project.memberCount" />

                <ProjectShareDialog
                    :project-id="project.id"
                    :project-name="project.name"
                    :share="props.share"
                />

                <span class="bg-border h-5 w-px" aria-hidden="true"></span>

                <ProjectCustomizeSheet
                    v-if="project.canCustomize"
                    :project-id="project.id"
                    :can-manage="project.canCustomize"
                    :customize="props.customize"
                />
            </div>
        </div>

        <div class="px-2 md:px-4">
            <ViewSwitcher :project-id="project.id" :current="view" :views="views" />
        </div>
    </header>
</template>
