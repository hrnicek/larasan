<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import WorkspaceController from '@/actions/App/Http/Controllers/Workspace/WorkspaceController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
</script>

<template>
    <div class="flex flex-col space-y-6">
        <Head title="Create a workspace" />

        <Heading
            title="Create a workspace"
            description="Projects, tasks and members all live inside a workspace"
        />

        <Form
            v-bind="WorkspaceController.store.form()"
            class="max-w-lg space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" name="name" required autofocus placeholder="Acme Industries" />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="timezone">Time zone</Label>
                <Input id="timezone" name="timezone" placeholder="UTC" />
                <InputError :message="errors.timezone" />
            </div>

            <Button type="submit" :disabled="processing">Create workspace</Button>
        </Form>
    </div>
</template>
