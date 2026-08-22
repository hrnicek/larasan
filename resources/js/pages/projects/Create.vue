<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import ProjectController from '@/actions/App/Http/Controllers/Project/ProjectController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
</script>

<template>
    <div class="flex flex-col space-y-6">
        <Head title="Create a project" />

        <Heading title="Create a project" description="Tasks, sections and members live inside a project" />

        <Form v-bind="ProjectController.store.form()" class="max-w-lg space-y-6" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" name="name" required autofocus placeholder="Web redesign" />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="slug">Address</Label>
                <Input id="slug" name="slug" placeholder="web-redesign" />
                <InputError :message="errors.slug" />
            </div>

            <Button type="submit" :disabled="processing">Create project</Button>
        </Form>
    </div>
</template>
