<script setup lang="ts">
import type { Component } from 'vue';

withDefaults(
    defineProps<{
        id: string;
        title: string;
        description?: string;
        icon?: Component;
        tone?: 'default' | 'danger';
    }>(),
    { tone: 'default' },
);
</script>

<template>
    <section
        :id="id"
        tabindex="-1"
        class="scroll-mt-6 rounded-xl border bg-card focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
        :class="tone === 'danger' ? 'border-destructive/30' : 'border-border'"
    >
        <header class="flex items-start gap-3 border-b border-border px-6 py-4">
            <span
                v-if="icon"
                class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md"
                :class="
                    tone === 'danger'
                        ? 'bg-destructive/10 text-destructive'
                        : 'bg-muted text-muted-foreground'
                "
                aria-hidden="true"
            >
                <component :is="icon" class="size-4" />
            </span>

            <div class="min-w-0">
                <h3 class="text-base font-semibold">{{ title }}</h3>
                <p
                    v-if="description"
                    class="mt-0.5 text-sm text-muted-foreground"
                >
                    {{ description }}
                </p>
            </div>

            <div v-if="$slots.aside" class="ml-auto shrink-0">
                <slot name="aside" />
            </div>
        </header>

        <div class="px-6 py-5">
            <slot />
        </div>
    </section>
</template>
