<script setup lang="ts">
import { computed } from 'vue';

type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
    level?: 'h1' | 'h2' | 'h3';
};

const props = withDefaults(defineProps<Props>(), {
    variant: 'default',
});

const tag = computed<string>(
    () => props.level ?? (props.variant === 'small' ? 'h2' : 'h1'),
);
</script>

<template>
    <header :class="variant === 'small' ? '' : 'mb-8 space-y-0.5'">
        <component
            :is="tag"
            :class="
                variant === 'small'
                    ? 'mb-0.5 text-base font-medium'
                    : 'text-xl font-semibold tracking-tight'
            "
        >
            {{ title }}
        </component>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
    </header>
</template>
