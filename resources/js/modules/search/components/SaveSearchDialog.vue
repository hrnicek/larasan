<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import SavedSearchController from '@/actions/App/Http/Controllers/Search/SavedSearchController';
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
    term: string;
    filters: { project?: string; assignee?: number; completed?: boolean };
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
                <DialogTitle>{{ t('Save this search') }}</DialogTitle>
                <DialogDescription>{{
                    t(
                        'It becomes a chip in the palette, with the filters it has now.',
                    )
                }}</DialogDescription>
            </DialogHeader>

            <Form
                v-bind="SavedSearchController.store.form()"
                :options="{ preserveScroll: true, preserveState: true }"
                class="space-y-4"
                @success="emit('update:open', false)"
                v-slot="{ errors, processing }"
            >
                <input type="hidden" name="term" :value="props.term" />
                <input
                    v-if="props.filters.project"
                    type="hidden"
                    name="project"
                    :value="props.filters.project"
                />
                <input
                    v-if="props.filters.assignee"
                    type="hidden"
                    name="assignee"
                    :value="props.filters.assignee"
                />
                <input
                    v-if="props.filters.completed !== undefined"
                    type="hidden"
                    name="completed"
                    :value="props.filters.completed ? '1' : '0'"
                />

                <div class="grid gap-2">
                    <Label for="saved-search-name">{{ t('Name') }}</Label>
                    <Input
                        id="saved-search-name"
                        name="name"
                        :default-value="props.term"
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
                        t('Save search')
                    }}</Button>
                </div>
            </Form>
        </DialogContent>
    </Dialog>
</template>
