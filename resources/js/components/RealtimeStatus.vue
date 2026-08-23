<script setup lang="ts">
import { computed } from 'vue';
import { useRealtimeConnection } from '@/composables/useRealtime';

/**
 * Says that live updates are not arriving, and nothing else.
 *
 * Realtime is an enhancement (ADR-0008): every screen works by asking the server, which is what
 * it does without a socket too. So this is a line of muted text rather than a banner — a person
 * whose connection dropped for two seconds does not need to be interrupted, and one whose
 * connection is gone should be able to see why the board stopped moving.
 */
const connection = useRealtimeConnection();

const label = computed<string | null>(() => {
    switch (connection.value) {
        case 'offline':
            return 'Live updates paused — reconnecting';
        default:
            return null;
    }
});
</script>

<template>
    <p v-if="label !== null" class="px-2 py-1 text-xs text-muted-foreground" role="status" aria-live="polite">
        {{ label }}
    </p>
</template>
