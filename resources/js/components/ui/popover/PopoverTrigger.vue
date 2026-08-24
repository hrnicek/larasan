<script setup lang="ts">
import type { PopoverTriggerProps } from 'reka-ui';
import type { HTMLAttributes } from 'vue';
import { reactiveOmit } from '@vueuse/core';
import { PopoverTrigger, useForwardProps } from 'reka-ui';
import { cn } from '@/lib/utils';

const props = defineProps<PopoverTriggerProps & { class?: HTMLAttributes['class'] }>();

const forwarded = useForwardProps(reactiveOmit(props, 'class'));
</script>

<template>
    <PopoverTrigger data-slot="popover-trigger" v-bind="forwarded" :class="cn(props.class)">
        <slot />
    </PopoverTrigger>
</template>
