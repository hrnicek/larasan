<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import WorkspaceInvitationController from '@/actions/App/Http/Controllers/Workspace/WorkspaceInvitationController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { formatFeedTime } from '@/lib/feedTime';
import { t } from '@/lib/i18n';
import { roleName } from '@/modules/workspace/roles';
import type {
    WorkspaceInvitation,
    WorkspaceSummary,
} from '@/modules/workspace/types';
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
    router.post(
        WorkspaceInvitationController.decline.url(invitation.id),
        {},
        { preserveScroll: true },
    );
}

function invitedBy(invitation: WorkspaceInvitation): string {
    return invitation.invitedBy === null
        ? t('Invited as :role', { role: roleName(invitation.role) })
        : t(':name invited you as :role', {
              name: invitation.invitedBy,
              role: roleName(invitation.role),
          });
}
</script>

<template>
    <div class="flex max-w-3xl flex-col space-y-6 p-4 md:p-6">
        <Head :title="t('Workspaces')" />

        <Heading
            :title="t('Workspaces')"
            :description="t('The workspaces you belong to')"
        />

        <section v-if="invitations.length > 0" class="flex flex-col gap-3">
            <h2 class="text-sm font-medium">{{ t('Invitations') }}</h2>

            <ul class="divide-y rounded-lg border">
                <li
                    v-for="invitation in invitations"
                    :key="invitation.id"
                    class="flex flex-wrap items-center justify-between gap-3 p-4"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">
                            {{ invitation.workspace }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ invitedBy(invitation) }}
                            <template v-if="invitation.hasExpired">
                                · {{ t('expired') }}</template
                            >
                            <template v-else-if="invitation.expiresAt">
                                ·
                                {{
                                    t('expires :time', {
                                        time: formatFeedTime(
                                            invitation.expiresAt,
                                        ),
                                    })
                                }}
                            </template>
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <Button
                            v-if="!invitation.hasExpired"
                            @click="accept(invitation)"
                        >
                            {{ t('Accept') }}
                        </Button>
                        <Button variant="ghost" @click="decline(invitation)">
                            {{
                                invitation.hasExpired
                                    ? t('Dismiss')
                                    : t('Decline')
                            }}
                        </Button>
                    </div>
                </li>
            </ul>
        </section>

        <div
            v-if="workspaces.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <p class="text-sm text-muted-foreground">
                {{ t('You are not a member of any workspace yet.') }}
            </p>
            <Button as-child class="mt-4">
                <Link :href="create()">{{ t('Create a workspace') }}</Link>
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
                        <p class="truncate text-xs text-muted-foreground">
                            {{ workspace.slug }}
                        </p>
                    </div>

                    <Button
                        v-if="workspace.id === currentId"
                        as-child
                        variant="outline"
                    >
                        <Link
                            :href="edit()"
                            component="settings/Workspace"
                            prefetch="click"
                            >{{ t('Settings') }}</Link
                        >
                    </Button>
                    <Button v-else variant="ghost" @click="open(workspace)">
                        {{ t('Open') }}
                    </Button>
                </li>
            </ul>

            <Button as-child variant="outline" class="self-start">
                <Link :href="create()">{{ t('Create a workspace') }}</Link>
            </Button>
        </template>
    </div>
</template>
