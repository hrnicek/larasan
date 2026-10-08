<script setup lang="ts">
import AppShell from '@/components/AppShell.vue';
import ConnectionBanner from '@/components/ConnectionBanner.vue';
import { Toaster } from '@/components/ui/sonner';
</script>

<template>
    <AppShell>
        <ConnectionBanner />

        <slot />

        <Toaster />
    </AppShell>
</template>
