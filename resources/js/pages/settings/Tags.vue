<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import TagController from '@/actions/App/Http/Controllers/Tag/TagController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { accentChipClass, accentVars } from '@/lib/accentColor';
import AccentColorGrid from '@/modules/project/components/AccentColorGrid.vue';
import type { WorkspaceTag } from '@/modules/tag/types';

/**
 * The words this workspace uses for its work.
 *
 * The screen a tag could not be made from until now: Phase 140 built the Actions and the
 * endpoints and gave them no list to act on, so the vocabulary existed only as far as a seeder
 * had written it. Inventing or renaming one is `tag.manage`, because it changes what everybody
 * else's filters mean — applying one is an edit of a task and happens on the task.
 */
const props = defineProps<{
    tags: WorkspaceTag[];
    can: { manage: boolean };
}>();

const form = useForm<{ name: string; color: string | null }>({ name: '', color: null });

function submit(): void {
    form.post(TagController.store.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

/*
 * A tag is a word and an accent, so the row edits both at once — a rename that had to be followed
 * by a recolour would be two requests for one decision.
 */
const editing = ref<string | null>(null);
const edit = useForm<{ name: string; color: string | null }>({ name: '', color: null });

function startEditing(tag: WorkspaceTag): void {
    editing.value = tag.id;
    edit.clearErrors();
    edit.name = tag.name;
    edit.color = tag.color;
}

function save(tagId: string): void {
    edit.put(TagController.update.url(tagId), {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

const deleting = ref<WorkspaceTag | null>(null);

/** What deleting a tag costs, said before it is agreed to. The work stays; only the label goes. */
const deletionCost = computed(() => {
    const tag = deleting.value;

    if (tag === null) {
        return '';
    }

    if (tag.taskCount === 0) {
        return 'Nothing is labelled with it yet.';
    }

    return tag.taskCount === 1
        ? 'It comes off one task, which is not otherwise touched. Every saved filter that names it stops matching that task.'
        : `It comes off ${tag.taskCount} tasks, which are not otherwise touched. Every saved filter that names it stops matching them.`;
});

function confirmDeletion(): void {
    if (deleting.value === null) {
        return;
    }

    router.delete(TagController.destroy.url(deleting.value.id), {
        preserveScroll: true,
        onFinish: () => (deleting.value = null),
    });
}
</script>

<template>
    <div class="flex flex-col space-y-6">
        <Head title="Tags" />

        <h1 class="sr-only">Tags</h1>

        <Heading
            variant="small"
            title="Tags"
            description="The words this workspace uses for its work, and the colours they are drawn in"
        />

        <form v-if="props.can.manage" class="space-y-4 rounded-lg border p-4" @submit.prevent="submit">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
                <div class="grid flex-1 gap-2">
                    <Label for="tag-name">Name</Label>
                    <Input id="tag-name" v-model="form.name" required maxlength="40" placeholder="Bug" />
                    <InputError :message="form.errors.name" />
                </div>

                <Button type="submit" :disabled="form.processing">Add tag</Button>
            </div>

            <AccentColorGrid v-model="form.color" :disabled="form.processing" />
            <InputError :message="form.errors.color" />
        </form>

        <p v-if="!props.tags.length" class="text-muted-foreground rounded-lg border border-dashed p-6 text-sm">
            No tags yet. A tag is one word about a piece of work — a bug, a client, a release — and
            every board and every search can filter by it.
        </p>

        <ul v-else class="divide-y rounded-lg border">
            <li v-for="tag in props.tags" :key="tag.id" class="p-4">
                <div v-if="editing === tag.id" class="space-y-4">
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="min-w-48 flex-1">
                            <Label :for="`name-${tag.id}`" class="sr-only">Tag name</Label>
                            <Input
                                :id="`name-${tag.id}`"
                                v-model="edit.name"
                                maxlength="40"
                                autofocus
                                @keyup.enter="save(tag.id)"
                            />
                            <InputError :message="edit.errors.name" />
                        </div>

                        <Button size="sm" :disabled="edit.processing" @click="save(tag.id)">Save</Button>
                        <Button size="sm" variant="ghost" @click="editing = null">Cancel</Button>
                    </div>

                    <AccentColorGrid v-model="edit.color" :disabled="edit.processing" />
                </div>

                <div v-else class="flex flex-wrap items-center gap-3">
                    <span
                        class="shrink-0 rounded-md px-2 py-0.5 text-xs font-medium"
                        :class="accentChipClass(tag.color)"
                        :style="accentVars(tag.color)"
                    >
                        {{ tag.name }}
                    </span>

                    <span class="text-muted-foreground flex-1 truncate text-xs">
                        {{ tag.taskCount === 1 ? 'on 1 task' : `on ${tag.taskCount} tasks` }}
                    </span>

                    <div v-if="props.can.manage" class="flex shrink-0 items-center gap-0.5">
                        <Button size="sm" variant="ghost" @click="startEditing(tag)">Edit</Button>
                        <Button size="sm" variant="ghost" @click="deleting = tag">Delete</Button>
                    </div>
                </div>
            </li>
        </ul>

        <ConfirmDialog
            :open="deleting !== null"
            :title="`Delete ${deleting?.name}?`"
            :description="deletionCost"
            confirm-label="Delete tag"
            cancel-label="Keep it"
            @update:open="(next) => !next && (deleting = null)"
            @confirm="confirmDeletion"
        />
    </div>
</template>
