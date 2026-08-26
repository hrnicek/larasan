<script setup lang="ts">
import { Modal } from '@inertiaui/modal-vue';
import { X } from '@lucide/vue';
import { ref, useId, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { provideModalPortalTarget } from '@/composables/useModalPortalTarget';

const props = defineProps<{
    title: string;
    description?: string;
    /**
     * The package's own scale, forwarded. Omitted, a dialog keeps the width every dialog in this
     * application has (`lib/modalLayer.ts`); one that is a working surface rather than a question
     * asks for a wider one.
     */
    maxWidth?: '2xl' | '3xl' | '4xl' | '5xl';
}>();

const titleId = useId();
const descriptionId = useId();
const panel = ref<HTMLElement | null>(null);

/**
 * Where a popover or a select inside this dialog is teleported to. The dialog is opened with
 * `showModal()`, so anything portalled to `body` is inert underneath it — see
 * `composables/useModalPortalTarget.ts`.
 */
const portalTarget = provideModalPortalTarget();

/**
 * Name the dialog after its own heading.
 *
 * Inertia Modal renders a native `<dialog>` and leaves it unlabelled, so a screen reader
 * announces "dialog" and nothing else. The element belongs to the package, which is why the
 * label is attached from here rather than bound in a template — and why it is attached once, in
 * the one component every modal in this application is built from.
 *
 * Watched rather than done on mount: the panel lives in a slot the package renders only once
 * the modal is on the stack, so at mount there is no element yet to look upwards from.
 */
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
                    <h2 :id="titleId" class="text-base font-semibold tracking-tight text-foreground">{{ title }}</h2>
                    <p v-if="description" :id="descriptionId" class="text-sm text-muted-foreground">{{ description }}</p>
                </div>

                <Button variant="ghost" size="icon" class="-mt-1 -mr-1 size-7 shrink-0" aria-label="Close" @click="close">
                    <X class="size-4" />
                </Button>
            </header>

            <slot :close="close" />
        </div>
    </Modal>
</template>
