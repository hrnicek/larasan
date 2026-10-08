<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { WifiOff } from '@lucide/vue';
import { useReachability } from '@/composables/useRealtime';

// Reports network reachability only, never the websocket: Reverb can be down while the app still works.
const reachability = useReachability();
</script>

<template>
    <div
        v-if="reachability === 'offline'"
        role="status"
        aria-live="polite"
        class="flex items-center justify-center gap-3 border-b border-border bg-muted px-4 py-2 text-sm text-muted-foreground"
    >
        <WifiOff class="size-4 shrink-0" aria-hidden="true" />

        <span
            >No connection. Changes cannot be saved until the network is
            back.</span
        >

        <button
            type="button"
            class="font-medium text-foreground underline underline-offset-4 hover:no-underline"
            @click="router.reload()"
        >
            Try again
        </button>
    </div>
</template>
