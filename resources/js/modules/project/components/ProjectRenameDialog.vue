<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import ProjectNameController from '@/actions/App/Http/Controllers/Project/ProjectNameController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

const props = defineProps<{
    open: boolean;
    project: { id: string; name: string };
}>();

const emit = defineEmits<{ 'update:open': [boolean] }>();
</script>

<template>
    <Dialog
        :open="props.open"
        @update:open="(next) => emit('update:open', next)"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ t('Rename project') }}</DialogTitle>
                <DialogDescription>{{
                    t(
                        "The project's address stays as it is, so links to it keep working.",
                    )
                }}</DialogDescription>
            </DialogHeader>

            <Form
                v-bind="ProjectNameController.update.form(props.project.id)"
                :options="{ preserveScroll: true }"
                class="space-y-4"
                @success="emit('update:open', false)"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="project-name">{{ t('Name') }}</Label>
                    <Input
                        id="project-name"
                        name="name"
                        :default-value="props.project.name"
                        required
                        autofocus
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="flex justify-end gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        @click="emit('update:open', false)"
                        >{{ t('Cancel') }}</Button
                    >
                    <Button type="submit" :disabled="processing">{{
                        t('Save name')
                    }}</Button>
                </div>
            </Form>
        </DialogContent>
    </Dialog>
</template>
