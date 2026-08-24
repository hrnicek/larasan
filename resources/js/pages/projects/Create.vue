<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import ProjectController from '@/actions/App/Http/Controllers/Project/ProjectController';
import InputError from '@/components/InputError.vue';
import ModalShell from '@/components/ModalShell.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
</script>

<template>
    <ModalShell title="Create a project" description="Tasks, sections and members live inside a project" v-slot="{ close }">
        <Form v-bind="ProjectController.store.form()" class="space-y-5" v-slot="{ errors, processing }">
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

            <div class="flex justify-end gap-2 pt-1">
                <Button type="button" variant="ghost" @click="close">Cancel</Button>
                <Button type="submit" :disabled="processing">Create project</Button>
            </div>
        </Form>
    </ModalShell>
</template>
