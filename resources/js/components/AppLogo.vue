<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const name = usePage().props.name;
</script>

<template>
    <div class="flex items-center gap-2">
        <span class="flex size-7 shrink-0 items-center justify-center rounded-md bg-chrome-primary text-chrome-primary-foreground">
            <AppLogoIcon class="size-4" />
        </span>
        <span class="truncate text-sm font-semibold tracking-tight text-chrome-foreground">{{ name }}</span>
    </div>
</template>
