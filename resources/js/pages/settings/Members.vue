<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import WorkspaceMemberController from '@/actions/App/Http/Controllers/Workspace/WorkspaceMemberController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatFeedTime } from '@/lib/feedTime';
import { t } from '@/lib/i18n';
import { roleName } from '@/modules/workspace/roles';
import type {
    WorkspaceMember,
    WorkspacePendingInvitation,
} from '@/modules/workspace/types';

const props = defineProps<{
    members: WorkspaceMember[];
    invitations: WorkspacePendingInvitation[];
    roles: string[];
    can: { manageMembers: boolean };
}>();

const removing = ref<WorkspaceMember | null>(null);
const cancelling = ref<WorkspacePendingInvitation | null>(null);

// Only an owner may act on an owner; the server enforces this too. See ADR-0010.
const viewerIsOwner = computed(
    () => props.members.find((member) => member.isYou)?.role === 'owner',
);

function changeRole(id: string, role: string, current: string): void {
    if (role === current) {
        return;
    }

    router.put(
        WorkspaceMemberController.update.url(id),
        { role },
        { preserveScroll: true },
    );
}

function confirmRemoval(): void {
    if (removing.value === null) {
        return;
    }

    router.delete(WorkspaceMemberController.destroy.url(removing.value.id), {
        preserveScroll: true,
        onFinish: () => (removing.value = null),
    });
}

function confirmCancellation(): void {
    if (cancelling.value === null) {
        return;
    }

    router.delete(WorkspaceMemberController.destroy.url(cancelling.value.id), {
        preserveScroll: true,
        onFinish: () => (cancelling.value = null),
    });
}

function resend(invitation: WorkspacePendingInvitation): void {
    router.post(
        WorkspaceMemberController.resend.url(invitation.id),
        {},
        { preserveScroll: true },
    );
}

function invitationState(invitation: WorkspacePendingInvitation): string {
    const sender =
        invitation.invitedBy === null
            ? t('Invited')
            : t('Invited by :name', { name: invitation.invitedBy });

    if (invitation.hasExpired) {
        return `${sender} · ${t('expired')}`;
    }

    return invitation.expiresAt === null
        ? sender
        : `${sender} · ${t('expires :time', { time: formatFeedTime(invitation.expiresAt) })}`;
}
</script>

<template>
    <div class="flex flex-col space-y-6">
        <Head :title="t('Members')" />

        <h1 class="sr-only">{{ t('Workspace members') }}</h1>

        <Heading
            variant="small"
            :title="t('Members')"
            :description="
                t('Who belongs to this workspace, and what they may do')
            "
        />

        <Form
            v-if="can.manageMembers"
            v-bind="WorkspaceMemberController.store.form()"
            class="flex flex-col gap-4 rounded-lg border p-4 sm:flex-row sm:items-end"
            v-slot="{ errors, processing }"
            :options="{ preserveScroll: true }"
            reset-on-success
        >
            <div class="grid flex-1 gap-2">
                <Label for="email">{{ t('Invite by email') }}</Label>
                <Input
                    id="email"
                    name="email"
                    type="email"
                    required
                    placeholder="person@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="role">{{ t('Role') }}</Label>
                <select
                    id="role"
                    name="role"
                    class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                >
                    <option v-for="role in roles" :key="role" :value="role">
                        {{ roleName(role) }}
                    </option>
                </select>
                <InputError :message="errors.role" />
            </div>

            <Button type="submit" :disabled="processing">{{
                t('Send invitation')
            }}</Button>
        </Form>

        <ul class="divide-y rounded-lg border">
            <li
                v-for="member in members"
                :key="member.id"
                class="flex flex-wrap items-center justify-between gap-3 p-4"
            >
                <div class="min-w-0">
                    <p class="truncate font-medium">
                        {{ member.name }}
                        <span
                            v-if="member.isYou"
                            class="text-xs text-muted-foreground"
                            >{{ t('(you)') }}</span
                        >
                    </p>
                    <p
                        v-if="member.email"
                        class="truncate text-xs text-muted-foreground"
                    >
                        {{ member.email }}
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <span
                        v-if="
                            !can.manageMembers ||
                            member.role === 'owner' ||
                            member.isYou
                        "
                        class="text-sm text-muted-foreground"
                    >
                        {{ roleName(member.role) }}
                    </span>

                    <Select
                        v-else
                        :model-value="member.role"
                        @update:model-value="
                            (role) =>
                                changeRole(member.id, String(role), member.role)
                        "
                    >
                        <SelectTrigger
                            class="w-36"
                            :aria-label="
                                t('Role for :name', { name: member.name })
                            "
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="role in roles"
                                :key="role"
                                :value="role"
                            >
                                {{ roleName(role) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Button
                        v-if="
                            can.manageMembers &&
                            !member.isLastOwner &&
                            !member.isYou &&
                            (member.role !== 'owner' || viewerIsOwner)
                        "
                        variant="ghost"
                        @click="removing = member"
                    >
                        {{ t('Remove') }}
                    </Button>
                </div>
            </li>
        </ul>

        <section v-if="invitations.length > 0" class="flex flex-col gap-3">
            <h2 class="text-sm font-medium">{{ t('Pending invitations') }}</h2>

            <ul class="divide-y rounded-lg border">
                <li
                    v-for="invitation in invitations"
                    :key="invitation.id"
                    class="flex flex-wrap items-center justify-between gap-3 p-4"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">
                            {{ invitation.name ?? invitation.email }}
                            <span
                                v-if="!invitation.hasAccount"
                                class="text-xs text-muted-foreground"
                            >
                                {{ t('(no account yet)') }}
                            </span>
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            <template v-if="invitation.name"
                                >{{ invitation.email }} ·
                            </template>
                            {{ invitationState(invitation) }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <Select
                            :model-value="invitation.role"
                            @update:model-value="
                                (role) =>
                                    changeRole(
                                        invitation.id,
                                        String(role),
                                        invitation.role,
                                    )
                            "
                        >
                            <SelectTrigger
                                class="w-36"
                                :aria-label="
                                    t('Role for :name', {
                                        name: invitation.email,
                                    })
                                "
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="role in roles"
                                    :key="role"
                                    :value="role"
                                >
                                    {{ roleName(role) }}
                                </SelectItem>
                            </SelectContent>
                        </Select>

                        <Button variant="outline" @click="resend(invitation)">{{
                            t('Resend')
                        }}</Button>
                        <Button
                            variant="ghost"
                            @click="cancelling = invitation"
                            >{{ t('Cancel') }}</Button
                        >
                    </div>
                </li>
            </ul>
        </section>

        <ConfirmDialog
            :open="removing !== null"
            :title="t('Remove :name?', { name: removing?.name ?? '' })"
            :description="
                t(
                    'They lose access to this workspace immediately. Their comments and activity stay, and they can be invited again later.',
                )
            "
            :confirm-label="t('Remove')"
            :cancel-label="t('Keep them')"
            @update:open="(next) => !next && (removing = null)"
            @confirm="confirmRemoval"
        />

        <ConfirmDialog
            :open="cancelling !== null"
            :title="
                t('Cancel the invitation to :email?', {
                    email: cancelling?.email ?? '',
                })
            "
            :description="
                t(
                    'The link in their invitation stops working. Nothing else changes, and the address can be invited again.',
                )
            "
            :confirm-label="t('Cancel invitation')"
            :cancel-label="t('Keep it')"
            @update:open="(next) => !next && (cancelling = null)"
            @confirm="confirmCancellation"
        />
    </div>
</template>
