<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import WorkspaceController from '@/actions/App/Http/Controllers/Workspace/WorkspaceController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { WorkspaceAbilities, WorkspaceSettings } from '@/modules/workspace/types';

defineProps<{
    workspace: WorkspaceSettings;
    can: WorkspaceAbilities;
}>();
</script>

<template>
    <div class="flex flex-col space-y-6">
        <Head title="Workspace settings" />

        <h1 class="sr-only">Workspace settings</h1>

        <Heading
            variant="small"
            title="Workspace"
            description="Name, address and time zone"
        />

        <p v-if="!can.update" class="text-muted-foreground text-sm">
            You can view this workspace but not change its settings.
        </p>

        <Form
            v-else
            v-bind="WorkspaceController.update.form()"
            class="max-w-lg space-y-6"
            v-slot="{ errors, processing }"
        >
            <input type="hidden" name="id" :value="workspace.id" />

            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" name="name" required :default-value="workspace.name" />
                <InputError :message="errors.name" />
                <InputError :message="errors.id" />
            </div>

            <div class="grid gap-2">
                <Label for="slug">Address</Label>
                <Input id="slug" name="slug" :default-value="workspace.slug" />
                <InputError :message="errors.slug" />
            </div>

            <div class="grid gap-2">
                <Label for="timezone">Time zone</Label>
                <Input id="timezone" name="timezone" :default-value="workspace.timezone" />
                <InputError :message="errors.timezone" />
            </div>

            <Button type="submit" :disabled="processing">Save changes</Button>
        </Form>
    </div>
</template>
