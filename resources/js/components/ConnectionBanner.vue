<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { WifiOff } from '@lucide/vue';
import { useReachability } from '@/composables/useRealtime';

/*
 * The shell saying the connection is gone, rather than the application appearing to work and
 * quietly answering nothing.
 *
 * It reports the *network*, never the websocket: Reverb can be stopped on a machine whose network
 * is perfect, and telling somebody they are offline because live updates paused would be a lie
 * about a screen that still works.
 */
const reachability = useReachability();
</script>

<template>
    <!--
        `role="status"` with a polite live region: a screen reader is told once, when it changes,
        without interrupting whatever is being read.
    -->
    <div
        v-if="reachability === 'offline'"
        role="status"
        aria-live="polite"
        class="flex items-center justify-center gap-3 border-b border-border bg-muted px-4 py-2 text-sm text-muted-foreground"
    >
        <WifiOff class="size-4 shrink-0" aria-hidden="true" />

        <span>No connection. Changes cannot be saved until the network is back.</span>

        <!--
            Coming back online recovers on its own. This is for the other case: a network the
            device believes it has, which cannot reach the server.
        -->
        <button
            type="button"
            class="font-medium text-foreground underline underline-offset-4 hover:no-underline"
            @click="router.reload()"
        >
            Try again
        </button>
    </div>
</template>
