<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * The one way this application asks "are you sure".
 *
 * Destructive work never runs optimistically and never rides on a modal's own state
 * (ADR-0013): the dialog asks, the caller acts. Written once because the shape of the question
 * is what makes it answerable — the title names the thing, the description says what is lost and
 * what is not, and the confirming control repeats the verb rather than saying "OK".
 */
withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        description: string;
        /** The verb, repeated. "OK" is not an answer to "Delete this?". */
        confirmLabel: string;
        cancelLabel?: string;
        pending?: boolean;
    }>(),
    {
        cancelLabel: 'Cancel',
        pending: false,
    },
);

const emit = defineEmits<{ 'update:open': [boolean]; confirm: [] }>();
</script>

<template>
    <Dialog :open="open" @update:open="(next) => emit('update:open', next)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>

            <DialogFooter>
                <Button
                    variant="ghost"
                    :disabled="pending"
                    @click="emit('update:open', false)"
                >
                    {{ cancelLabel }}
                </Button>
                <Button
                    variant="destructive"
                    :disabled="pending"
                    @click="emit('confirm')"
                >
                    {{ confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
