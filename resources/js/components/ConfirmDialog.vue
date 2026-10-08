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

withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        description: string;
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
