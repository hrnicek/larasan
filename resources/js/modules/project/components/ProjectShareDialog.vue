<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, Globe, Link2, Lock } from '@lucide/vue';
import { ref, watch } from 'vue';
import ProjectMemberController from '@/actions/App/Http/Controllers/Project/ProjectMemberController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import UserAvatar from '@/components/UserAvatar.vue';
import { reloadOptional } from '@/lib/optionalProps';
import type { ProjectMember, ProjectShare } from '@/modules/project/types';

const props = defineProps<{
    projectId: string;
    projectName: string;
    share?: ProjectShare;
}>();

const open = ref(false);
const chosen = ref<string>('');
const level = ref('editor');
const copied = ref(false);

// `share` is an optional prop: reload it on open and after every write, since redirects omit it.
watch(open, (isOpen) => {
    if (isOpen) {
        reload();

        return;
    }

    chosen.value = '';
    copied.value = false;
});

function reload(): void {
    // `reloadOptional` defers the visit; one started inside a write's callbacks would be aborted.
    reloadOptional(['share']);
}

// Last server copy, kept because redirects omit the optional prop.
const share = ref<ProjectShare | null>(props.share ?? null);

watch(
    () => props.share,
    (next) => {
        if (next !== undefined) {
            share.value = next;
        }
    },
    { immediate: true },
);

const levelLabels: Record<string, string> = {
    owner: 'Project owner',
    editor: 'Editor',
    commenter: 'Commenter',
    viewer: 'Viewer',
};

function labelFor(value: string): string {
    return levelLabels[value] ?? value;
}

function add(): void {
    if (chosen.value === '') {
        return;
    }

    router.post(
        ProjectMemberController.store.url(props.projectId),
        { user: chosen.value, access_level: level.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                chosen.value = '';
                reload();
            },
        },
    );
}

function changeLevel(member: ProjectMember, next: string): void {
    if (next === member.accessLevel) {
        return;
    }

    router.put(
        ProjectMemberController.update.url({ project: props.projectId, membership: member.membershipId }),
        { access_level: next },
        { preserveScroll: true, onSuccess: reload },
    );
}

function revoke(member: ProjectMember): void {
    router.delete(
        ProjectMemberController.destroy.url({ project: props.projectId, membership: member.membershipId }),
        { preserveScroll: true, onSuccess: reload },
    );
}

async function copyLink(): Promise<void> {
    const link = share.value?.link;

    if (link === undefined) {
        return;
    }

    try {
        await navigator.clipboard.writeText(link);
        copied.value = true;
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button size="sm">
                <Lock v-if="share?.visibility === 'private'" class="size-4" />
                <Globe v-else class="size-4" />
                Share
            </Button>
        </DialogTrigger>

        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Share {{ props.projectName }}</DialogTitle>
                <DialogDescription>Who may reach this project, and what they may do here</DialogDescription>
            </DialogHeader>

            <div v-if="share === null" class="space-y-3" aria-hidden="true">
                <Skeleton class="h-9 w-full rounded-md" />
                <Skeleton class="h-12 w-full rounded-md" />
                <Skeleton class="h-12 w-full rounded-md" />
            </div>

            <template v-else>
                <!-- `min-w-0` and `w-full` stop long select options from widening the dialog's grid track. -->
                <div v-if="share.canManage" class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-end">
                    <div class="grid min-w-0 flex-1 gap-2">
                        <Label for="share-person">Add someone from this workspace</Label>
                        <select
                            id="share-person"
                            v-model="chosen"
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                        >
                            <option value="">Choose a person…</option>
                            <option v-for="person in share.candidates" :key="person.id" :value="person.id">
                                {{ person.email ? `${person.name} — ${person.email}` : person.name }}
                            </option>
                        </select>
                    </div>

                    <div class="grid gap-2">
                        <Label for="share-level">Access</Label>
                        <select
                            id="share-level"
                            v-model="level"
                            class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        >
                            <option v-for="value in share.accessLevels" :key="value" :value="value">
                                {{ labelFor(value) }}
                            </option>
                        </select>
                    </div>

                    <Button :disabled="chosen === ''" @click="add">Add</Button>
                </div>

                <p
                    v-if="share.canManage && !share.candidates.length"
                    class="text-muted-foreground text-xs"
                >
                    Everybody in this workspace already has access. Somebody new is invited in
                    workspace settings first.
                </p>

                <div class="flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm">
                    <component :is="share.visibility === 'private' ? Lock : Globe" class="size-4 text-muted-foreground" />
                    <span class="flex-1">
                        {{
                            share.visibility === 'private'
                                ? 'Private — only the people below'
                                : 'Workspace — anybody in this workspace can open it'
                        }}
                    </span>
                </div>

                <ul class="max-h-72 divide-y overflow-y-auto rounded-lg border">
                    <li v-for="member in share.members" :key="member.membershipId" class="flex items-center gap-3 p-3">
                        <UserAvatar :user="{ name: member.name, avatar: member.avatar }" size="sm" />

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">
                                {{ member.name }}
                                <span v-if="member.isYou" class="text-muted-foreground text-xs">(you)</span>
                            </p>
                            <p v-if="member.email" class="text-muted-foreground truncate text-xs">{{ member.email }}</p>
                        </div>

                        <span
                            v-if="!share.canManage || member.isLastOwner"
                            class="text-muted-foreground shrink-0 text-sm"
                        >
                            {{ labelFor(member.accessLevel) }}
                        </span>

                        <template v-else>
                            <select
                                :value="member.accessLevel"
                                class="border-input bg-background h-8 shrink-0 rounded-md border px-2 text-sm"
                                :aria-label="`Access for ${member.name}`"
                                @change="changeLevel(member, ($event.target as HTMLSelectElement).value)"
                            >
                                <option v-for="value in share.accessLevels" :key="value" :value="value">
                                    {{ labelFor(value) }}
                                </option>
                            </select>

                            <Button
                                variant="ghost"
                                size="sm"
                                :aria-label="`Remove ${member.name} from this project`"
                                @click="revoke(member)"
                            >
                                Remove
                            </Button>
                        </template>
                    </li>
                </ul>

                <div class="flex items-center justify-end">
                    <Button variant="outline" size="sm" @click="copyLink">
                        <component :is="copied ? Check : Link2" class="size-4" />
                        {{ copied ? 'Link copied' : 'Copy project link' }}
                    </Button>
                </div>
            </template>
        </DialogContent>
    </Dialog>
</template>
