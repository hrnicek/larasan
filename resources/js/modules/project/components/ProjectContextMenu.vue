<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Archive, Copy, ExternalLink, Palette, PencilLine, Settings, Star, StarOff } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import {
    ContextMenu,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuSeparator,
    ContextMenuSub,
    ContextMenuSubContent,
    ContextMenuSubTrigger,
    ContextMenuTrigger,
} from '@/components/ui/context-menu';
import ProjectAppearanceFields from '@/modules/project/components/ProjectAppearanceFields.vue';
import ProjectRenameDialog from '@/modules/project/components/ProjectRenameDialog.vue';
import type { SidebarProject } from '@/modules/project/types';
import { archive, edit, show, star, unstar } from '@/routes/projects';

/**
 * What can be done to a project, from the row that names it.
 *
 * Every item is an endpoint that already exists: this is a second door, not a new room. Which
 * items are drawn comes from the two flags the server computed with the project policy — hiding
 * a control is presentation, and each endpoint refuses the same reader again.
 */
const props = defineProps<{ project: SidebarProject }>();

const renaming = ref(false);
const archiving = ref(false);
const working = ref(false);

/** Absolute, because a link is pasted somewhere this application is not. */
async function copyLink(): Promise<void> {
    const url = `${window.location.origin}${show(props.project.id).url}`;

    try {
        await navigator.clipboard.writeText(url);
        toast('Link copied.');
    } catch {
        // A browser refuses the clipboard outside a secure context, and a toast that lies about
        // it leaves somebody pasting whatever was there before.
        toast('Could not copy — open the project and use the address bar.');
    }
}

/**
 * The star is this reader's own, so the write is theirs alone and the server answers with the
 * sidebar re-ordered around it. `preserveScroll` because a row moving into the starred group is
 * the whole point, and a rail that jumps to the top with it is not.
 */
function toggleStar(): void {
    if (props.project.starred) {
        router.delete(unstar(props.project.id).url, { preserveScroll: true });

        return;
    }

    router.post(star(props.project.id).url, {}, { preserveScroll: true });
}

function archiveProject(): void {
    working.value = true;

    router.put(
        archive(props.project.id).url,
        {},
        {
            onFinish: () => {
                working.value = false;
                archiving.value = false;
            },
        },
    );
}
</script>

<template>
    <ContextMenu>
        <!--
            A wrapper rather than `as-child`: the row it wraps is a tooltip around a link, and a
            tooltip root renders no element of its own for a trigger to bind itself to.
        -->
        <ContextMenuTrigger as="div">
            <slot />
        </ContextMenuTrigger>

        <ContextMenuContent class="w-56">
            <ContextMenuItem as-child>
                <a :href="show(props.project.id).url" target="_blank" rel="noopener" class="cursor-default">
                    <ExternalLink class="mr-2 size-4" />
                    Open in new tab
                </a>
            </ContextMenuItem>

            <ContextMenuItem @select="copyLink">
                <Copy class="mr-2 size-4" />
                Copy link
            </ContextMenuItem>

            <ContextMenuSeparator />

            <!--
                The same palette and library the project header opens, in a submenu rather than a
                popover: the grids are one component, so a colour picked here and a colour picked
                there are the same two columns written by the same endpoint.
            -->
            <ContextMenuSub v-if="props.project.canUpdate">
                <ContextMenuSubTrigger>
                    <Palette class="mr-2 size-4" />
                    Set colour &amp; icon
                </ContextMenuSubTrigger>
                <ContextMenuSubContent class="w-72 p-3">
                    <ProjectAppearanceFields :project="props.project" />
                </ContextMenuSubContent>
            </ContextMenuSub>

            <ContextMenuItem v-if="props.project.canUpdate" @select="renaming = true">
                <PencilLine class="mr-2 size-4" />
                Rename
            </ContextMenuItem>

            <ContextMenuItem @select="toggleStar">
                <component :is="props.project.starred ? StarOff : Star" class="mr-2 size-4" />
                {{ props.project.starred ? 'Remove from starred' : 'Add to starred' }}
            </ContextMenuItem>

            <ContextMenuItem as-child>
                <Link :href="edit(props.project.id).url" component="projects/Settings" class="w-full cursor-default">
                    <Settings class="mr-2 size-4" />
                    Project settings
                </Link>
            </ContextMenuItem>

            <template v-if="props.project.canArchive">
                <ContextMenuSeparator />
                <ContextMenuItem variant="destructive" @select="archiving = true">
                    <Archive class="mr-2 size-4" />
                    Archive project
                </ContextMenuItem>
            </template>
        </ContextMenuContent>
    </ContextMenu>

    <ProjectRenameDialog
        :open="renaming"
        :project="props.project"
        @update:open="(next) => (renaming = next)"
    />

    <ConfirmDialog
        :open="archiving"
        :title="`Archive ${props.project.name}?`"
        description="Nothing is deleted. The project leaves the sidebar and becomes read-only, and it can be restored from its settings."
        confirm-label="Archive project"
        cancel-label="Keep it open"
        :pending="working"
        @update:open="(next) => (archiving = next)"
        @confirm="archiveProject"
    />
</template>
