<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <header class="border-b border-border">
        <div
            class="flex flex-wrap items-center gap-3 px-4 pt-4 md:px-6"
            :class="$slots.tabs ? 'pb-3' : 'pb-4'"
        >
            <div class="min-w-0">
                <h1 class="truncate text-xl font-semibold tracking-tight">
                    {{ title }}
                </h1>
                <p
                    v-if="description"
                    class="mt-0.5 truncate text-sm text-muted-foreground"
                >
                    {{ description }}
                </p>
            </div>

            <div v-if="$slots.actions" class="ml-auto shrink-0">
                <slot name="actions" />
            </div>
        </div>

        <div v-if="$slots.tabs" class="px-2 md:px-4">
            <slot name="tabs" />
        </div>
    </header>
</template>
