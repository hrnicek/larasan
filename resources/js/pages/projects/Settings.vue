<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import ProjectController from '@/actions/App/Http/Controllers/Project/ProjectController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ProjectAbilities, ProjectSettings } from '@/modules/project/types';

const props = defineProps<{
    project: ProjectSettings;
    can: ProjectAbilities;
}>();
</script>

<template>
    <div class="flex flex-col space-y-6">
        <Head :title="props.project.name" />

        <Heading :title="props.project.name" description="Project settings" />

        <Form
            v-bind="ProjectController.update.form(props.project.id)"
            class="max-w-lg space-y-6"
            v-slot="{ errors, processing }"
        >
            <input type="hidden" name="id" :value="props.project.id" />

            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" name="name" required :default-value="props.project.name" />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="slug">Address</Label>
                <Input id="slug" name="slug" :default-value="props.project.slug" />
                <InputError :message="errors.slug" />
            </div>

            <Button type="submit" :disabled="!props.can.update || processing">Save changes</Button>
        </Form>
    </div>
</template>
