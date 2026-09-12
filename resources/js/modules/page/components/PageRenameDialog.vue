<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import PageTitleController from '@/actions/App/Http/Controllers/Page/PageTitleController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    open: boolean;
    page: { id: string; title: string };
}>();

const emit = defineEmits<{ 'update:open': [boolean] }>();
</script>

<template>
    <Dialog :open="props.open" @update:open="(next) => emit('update:open', next)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Rename page</DialogTitle>
                <DialogDescription>A page left without a name is called Untitled.</DialogDescription>
            </DialogHeader>

            <Form
                v-bind="PageTitleController.update.form(props.page.id)"
                :options="{ preserveScroll: true }"
                class="space-y-4"
                @success="emit('update:open', false)"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="page-title">Title</Label>
                    <Input id="page-title" name="title" :default-value="props.page.title" autofocus />
                    <InputError :message="errors.title" />
                </div>

                <div class="flex justify-end gap-2">
                    <Button type="button" variant="ghost" @click="emit('update:open', false)">Cancel</Button>
                    <Button type="submit" :disabled="processing">Save title</Button>
                </div>
            </Form>
        </DialogContent>
    </Dialog>
</template>
