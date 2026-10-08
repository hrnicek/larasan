<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    Archive,
    Copy,
    ExternalLink,
    Palette,
    PencilLine,
    Settings,
    Star,
    StarOff,
} from '@lucide/vue';
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

const props = defineProps<{ project: SidebarProject }>();

const renaming = ref(false);
const archiving = ref(false);
const working = ref(false);

async function copyLink(): Promise<void> {
    const url = `${window.location.origin}${show(props.project.id).url}`;

    try {
        await navigator.clipboard.writeText(url);
        toast('Link copied.');
    } catch {
        // The Clipboard API is unavailable outside a secure context.
        toast('Could not copy — open the project and use the address bar.');
    }
}

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
        <!-- Not `as-child`: the slotted tooltip root renders no element for the trigger to bind to. -->
        <ContextMenuTrigger as="div">
            <slot />
        </ContextMenuTrigger>

        <ContextMenuContent class="w-56">
            <ContextMenuItem as-child>
                <a
                    :href="show(props.project.id).url"
                    target="_blank"
                    rel="noopener"
                    class="cursor-default"
                >
                    <ExternalLink class="mr-2 size-4" />
                    Open in new tab
                </a>
            </ContextMenuItem>

            <ContextMenuItem @select="copyLink">
                <Copy class="mr-2 size-4" />
                Copy link
            </ContextMenuItem>

            <ContextMenuSeparator />

            <ContextMenuSub v-if="props.project.canUpdate">
                <ContextMenuSubTrigger>
                    <Palette class="mr-2 size-4" />
                    Set colour &amp; icon
                </ContextMenuSubTrigger>
                <ContextMenuSubContent class="w-72 p-3">
                    <ProjectAppearanceFields :project="props.project" />
                </ContextMenuSubContent>
            </ContextMenuSub>

            <ContextMenuItem
                v-if="props.project.canUpdate"
                @select="renaming = true"
            >
                <PencilLine class="mr-2 size-4" />
                Rename
            </ContextMenuItem>

            <ContextMenuItem @select="toggleStar">
                <component
                    :is="props.project.starred ? StarOff : Star"
                    class="mr-2 size-4"
                />
                {{
                    props.project.starred
                        ? 'Remove from starred'
                        : 'Add to starred'
                }}
            </ContextMenuItem>

            <ContextMenuItem as-child>
                <Link
                    :href="edit(props.project.id).url"
                    component="projects/Settings"
                    class="w-full cursor-default"
                >
                    <Settings class="mr-2 size-4" />
                    Project settings
                </Link>
            </ContextMenuItem>

            <template v-if="props.project.canArchive">
                <ContextMenuSeparator />
                <ContextMenuItem
                    variant="destructive"
                    @select="archiving = true"
                >
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
