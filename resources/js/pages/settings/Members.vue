<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import WorkspaceMemberController from '@/actions/App/Http/Controllers/Workspace/WorkspaceMemberController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { WorkspaceMember } from '@/modules/workspace/types';
// Aliased: the page's own `members` prop would otherwise shadow the route helper.
import { members as membersRoute } from '@/routes/workspaces';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Members', href: membersRoute() }],
    },
});

defineProps<{
    members: WorkspaceMember[];
    roles: string[];
    can: { manageMembers: boolean };
}>();

const removing = ref<WorkspaceMember | null>(null);

function changeRole(member: WorkspaceMember, role: string): void {
    if (role === member.role) {
        return;
    }

    router.put(WorkspaceMemberController.update.url(member.id), { role }, { preserveScroll: true });
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
</script>

<template>
    <div class="flex flex-col space-y-6">
        <Head title="Members" />

        <h1 class="sr-only">Workspace members</h1>

        <Heading
            variant="small"
            title="Members"
            description="Who belongs to this workspace, and what they may do"
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
                <Label for="email">Invite by email</Label>
                <Input id="email" name="email" type="email" required placeholder="person@example.com" />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="role">Role</Label>
                <select
                    id="role"
                    name="role"
                    class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                >
                    <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
                </select>
                <InputError :message="errors.role" />
            </div>

            <Button type="submit" :disabled="processing">Send invitation</Button>
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
                        <span v-if="member.isYou" class="text-muted-foreground text-xs">(you)</span>
                    </p>
                    <p class="text-muted-foreground truncate text-xs">
                        <template v-if="member.email">{{ member.email }} · </template>{{ member.status }}
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <span
                        v-if="!can.manageMembers || member.role === 'owner' || member.isYou"
                        class="text-muted-foreground text-sm"
                    >
                        {{ member.role }}
                    </span>

                    <Select
                        v-else
                        :model-value="member.role"
                        @update:model-value="(role) => changeRole(member, String(role))"
                    >
                        <SelectTrigger class="w-36" :aria-label="`Role for ${member.name}`">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="role in roles" :key="role" :value="role">
                                {{ role }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Button
                        v-if="can.manageMembers && !member.isLastOwner && !member.isYou"
                        variant="ghost"
                        @click="removing = member"
                    >
                        Remove
                    </Button>
                </div>
            </li>
        </ul>

        <Dialog :open="removing !== null" @update:open="(open) => !open && (removing = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Remove {{ removing?.name }}?</DialogTitle>
                    <DialogDescription>
                        They lose access to this workspace immediately. Their comments and
                        activity stay, and they can be invited again later.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="removing = null">Keep them</Button>
                    <Button variant="destructive" @click="confirmRemoval">Remove</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
