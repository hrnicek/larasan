<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import WorkspaceInvitationController from '@/actions/App/Http/Controllers/Workspace/WorkspaceInvitationController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { formatFeedTime } from '@/lib/feedTime';
import type { WorkspaceInvitation, WorkspaceSummary } from '@/modules/workspace/types';
import { create, edit, switchMethod } from '@/routes/workspaces';

defineProps<{
    workspaces: WorkspaceSummary[];
    invitations: WorkspaceInvitation[];
}>();

const page = usePage();
const currentId = computed(() => page.props.workspace?.id ?? null);

function open(workspace: WorkspaceSummary): void {
    router.post(switchMethod(workspace.slug).url);
}

function accept(invitation: WorkspaceInvitation): void {
    router.post(WorkspaceInvitationController.accept.url(invitation.id));
}

function decline(invitation: WorkspaceInvitation): void {
    router.post(WorkspaceInvitationController.decline.url(invitation.id), {}, { preserveScroll: true });
}

function invitedBy(invitation: WorkspaceInvitation): string {
    return invitation.invitedBy === null
        ? `Invited as ${invitation.role}`
        : `${invitation.invitedBy} invited you as ${invitation.role}`;
}
</script>

<template>
    <div class="flex max-w-3xl flex-col space-y-6 p-4 md:p-6">
        <Head title="Workspaces" />

        <Heading
            title="Workspaces"
            description="The workspaces you belong to"
        />

        <section v-if="invitations.length > 0" class="flex flex-col gap-3">
            <h2 class="text-sm font-medium">Invitations</h2>

            <ul class="divide-y rounded-lg border">
                <li
                    v-for="invitation in invitations"
                    :key="invitation.id"
                    class="flex flex-wrap items-center justify-between gap-3 p-4"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ invitation.workspace }}</p>
                        <p class="text-muted-foreground truncate text-xs">
                            {{ invitedBy(invitation) }}
                            <template v-if="invitation.hasExpired"> · expired</template>
                            <template v-else-if="invitation.expiresAt">
                                · expires {{ formatFeedTime(invitation.expiresAt) }}
                            </template>
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <Button
                            v-if="!invitation.hasExpired"
                            @click="accept(invitation)"
                        >
                            Accept
                        </Button>
                        <Button variant="ghost" @click="decline(invitation)">
                            {{ invitation.hasExpired ? 'Dismiss' : 'Decline' }}
                        </Button>
                    </div>
                </li>
            </ul>
        </section>

        <div
            v-if="workspaces.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <p class="text-muted-foreground text-sm">
                You are not a member of any workspace yet.
            </p>
            <Button as-child class="mt-4">
                <Link :href="create()">Create a workspace</Link>
            </Button>
        </div>

        <template v-else>
            <ul class="divide-y rounded-lg border">
                <li
                    v-for="workspace in workspaces"
                    :key="workspace.id"
                    class="flex items-center justify-between gap-4 p-4"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ workspace.name }}</p>
                        <p class="text-muted-foreground truncate text-xs">{{ workspace.slug }}</p>
                    </div>

                    <Button
                        v-if="workspace.id === currentId"
                        as-child
                        variant="outline"
                    >
                        <Link :href="edit()" component="settings/Workspace" prefetch="click">Settings</Link>
                    </Button>
                    <Button v-else variant="ghost" @click="open(workspace)">
                        Open
                    </Button>
                </li>
            </ul>

            <Button as-child variant="outline" class="self-start">
                <Link :href="create()">Create a workspace</Link>
            </Button>
        </template>
    </div>
</template>
