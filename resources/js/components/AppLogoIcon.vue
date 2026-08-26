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
  The mark (ADR-0018): three slabs of unequal length with their ends cut on a slant — the keys of
  a gamelan in tune, and three task bars on a timeline. One path, three subpaths, no stroke and no
  gradient, so it survives being 16px in a browser tab and being recoloured for the maskable icon.

  The 3.2 bar to 3.4 gap ratio is what holds at 16px; a thicker bar closes the gap and the three
  slabs merge. The slant is what separates it from a hamburger menu, and the staggered lengths —
  medium, long, short — are what separate it from a chart.
-->
<template>
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" :class="className" v-bind="$attrs">
        <path
            fill="currentColor"
            d="M4.8 3.8H14.6L13 7H3.2Z M4.8 10.4H20.8L19.2 13.6H3.2Z M4.8 17H10.8L9.2 20.2H3.2Z"
        />
    </svg>
</template>
