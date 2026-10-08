<script setup lang="ts">
import { Modal } from '@inertiaui/modal-vue';
import { X } from '@lucide/vue';
import { ref, useId, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { provideModalPortalTarget } from '@/composables/useModalPortalTarget';

const props = defineProps<{
    title: string;
    description?: string;
    maxWidth?: '2xl' | '3xl' | '4xl' | '5xl';
}>();

const titleId = useId();
const descriptionId = useId();
const panel = ref<HTMLElement | null>(null);

// The dialog is opened with showModal(), so popovers portalled to body would be inert beneath it.
const portalTarget = provideModalPortalTarget();

// Inertia Modal leaves its native <dialog> unlabelled, and the slot only renders once the modal is stacked, hence a watch.
watch(panel, (element) => {
    const dialog = element?.closest('dialog, [role="dialog"]');

    if (!dialog) {
        return;
    }

    portalTarget.value = dialog instanceof HTMLElement ? dialog : null;

    dialog.setAttribute('aria-labelledby', titleId);

    if (props.description) {
        dialog.setAttribute('aria-describedby', descriptionId);
    }
});
</script>

<template>
    <Modal v-slot="{ close }" :close-button="false" :max-width="props.maxWidth">
        <div ref="panel" class="flex flex-col gap-5">
            <header class="flex items-start justify-between gap-4">
                <div class="space-y-1">
                    <h2
                        :id="titleId"
                        class="text-base font-semibold tracking-tight text-foreground"
                    >
                        {{ title }}
                    </h2>
                    <p
                        v-if="description"
                        :id="descriptionId"
                        class="text-sm text-muted-foreground"
                    >
                        {{ description }}
                    </p>
                </div>

                <Button
                    variant="ghost"
                    size="icon"
                    class="-mt-1 -mr-1 size-7 shrink-0"
                    aria-label="Close"
                    @click="close"
                >
                    <X class="size-4" />
                </Button>
            </header>

            <slot :close="close" />
        </div>
    </Modal>
</template>
