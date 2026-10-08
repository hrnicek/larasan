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

<!-- The 3.2 bar to 3.4 gap ratio keeps the slabs from merging at 16px. See ADR-0018. -->
<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        :class="className"
        v-bind="$attrs"
    >
        <path
            fill="currentColor"
            d="M4.8 3.8H14.6L13 7H3.2Z M4.8 10.4H20.8L19.2 13.6H3.2Z M4.8 17H10.8L9.2 20.2H3.2Z"
        />
    </svg>
</template>
