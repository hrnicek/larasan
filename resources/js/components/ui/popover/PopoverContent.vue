<script setup lang="ts">
import type { PopoverContentEmits, PopoverContentProps } from 'reka-ui';
import type { HTMLAttributes } from 'vue';
import { reactiveOmit } from '@vueuse/core';
import { PopoverContent, PopoverPortal, useForwardPropsEmits } from 'reka-ui';
import { useModalPortalTarget } from '@/composables/useModalPortalTarget';
import { cn } from '@/lib/utils';

defineOptions({
    inheritAttrs: false,
});

const props = withDefaults(defineProps<PopoverContentProps & { class?: HTMLAttributes['class'] }>(), {
    align: 'start',
    sideOffset: 4,
});

const emits = defineEmits<PopoverContentEmits>();

const forwarded = useForwardPropsEmits(reactiveOmit(props, 'class'), emits);

/** `body` everywhere except inside a modal, whose native dialog would render it inert. */
const portalTarget = useModalPortalTarget();
</script>

<template>
    <PopoverPortal :to="portalTarget ?? undefined">
        <PopoverContent
            data-slot="popover-content"
            v-bind="{ ...forwarded, ...$attrs }"
            :class="
                cn(
                    'bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=top]:slide-in-from-bottom-2 z-50 w-72 origin-(--reka-popover-content-transform-origin) rounded-md border shadow-md outline-none',
                    props.class,
                )
            "
        >
            <slot />
        </PopoverContent>
    </PopoverPortal>
</template>
