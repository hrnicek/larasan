<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import ProjectNameController from '@/actions/App/Http/Controllers/Project/ProjectNameController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * One field, because renaming is one decision.
 *
 * The address a project is reached at is not re-derived from the new name — every link anybody
 * saved would stop working — so the slug is changed from the settings form, where the field and
 * its consequence are both visible.
 */
const props = defineProps<{
    open: boolean;
    project: { id: string; name: string };
}>();

const emit = defineEmits<{ 'update:open': [boolean] }>();
</script>

<template>
    <Dialog :open="props.open" @update:open="(next) => emit('update:open', next)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Rename project</DialogTitle>
                <DialogDescription>The project's address stays as it is, so links to it keep working.</DialogDescription>
            </DialogHeader>

            <Form
                v-bind="ProjectNameController.update.form(props.project.id)"
                :options="{ preserveScroll: true }"
                class="space-y-4"
                @success="emit('update:open', false)"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="project-name">Name</Label>
                    <Input id="project-name" name="name" :default-value="props.project.name" required autofocus />
                    <InputError :message="errors.name" />
                </div>

                <div class="flex justify-end gap-2">
                    <Button type="button" variant="ghost" @click="emit('update:open', false)">Cancel</Button>
                    <Button type="submit" :disabled="processing">Save name</Button>
                </div>
            </Form>
        </DialogContent>
    </Dialog>
</template>
