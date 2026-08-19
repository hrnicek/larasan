<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { create, edit } from '@/routes/workspaces';

defineProps<{
    workspaces: Array<{ id: string; name: string; slug: string }>;
}>();
</script>

<template>
    <div class="flex flex-col space-y-6">
        <Head title="Workspaces" />

        <Heading
            title="Workspaces"
            description="The workspaces you belong to"
        />

        <div v-if="workspaces.length === 0" class="rounded-lg border border-dashed p-8 text-center">
            <p class="text-muted-foreground text-sm">
                You are not a member of any workspace yet.
            </p>
            <Button as-child class="mt-4">
                <Link :href="create()">Create a workspace</Link>
            </Button>
        </div>

        <ul v-else class="divide-y rounded-lg border">
            <li v-for="workspace in workspaces" :key="workspace.id" class="flex items-center justify-between p-4">
                <span class="font-medium">{{ workspace.name }}</span>
                <Button as-child variant="ghost">
                    <Link :href="edit(workspace.slug)">Settings</Link>
                </Button>
            </li>
        </ul>
    </div>
</template>
