<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import type { WorkspaceSummary } from '@/modules/workspace/types';
import { create, edit, switchMethod } from '@/routes/workspaces';

defineProps<{
    workspaces: WorkspaceSummary[];
}>();

const page = usePage();
const currentId = computed(() => page.props.workspace?.id ?? null);

function open(workspace: WorkspaceSummary): void {
    router.post(switchMethod(workspace.slug).url);
}
</script>

<template>
    <div class="flex flex-col space-y-6">
        <Head title="Workspaces" />

        <Heading
            title="Workspaces"
            description="The workspaces you belong to"
        />

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
                        <Link :href="edit()">Settings</Link>
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
