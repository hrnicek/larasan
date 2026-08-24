<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<!--
  The mark (ADR-0014): a flat-top hexagon — a basalt column seen end-on — split by a vertical
  slot left of centre. Two operations on one path, no stroke and no gradient, so it survives
  being 16px in a browser tab and being recoloured for the maskable icon.
-->
<template>
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" :class="className" v-bind="$attrs">
        <path
            fill="currentColor"
            fill-rule="evenodd"
            clip-rule="evenodd"
            d="M7 3.34h10L22 12l-5 8.66H7L2 12 7 3.34ZM9.25 3.34h3.5v17.32h-3.5V3.34Z"
        />
    </svg>
</template>
