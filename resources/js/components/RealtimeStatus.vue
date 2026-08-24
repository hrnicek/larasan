<script setup lang="ts">
import { WifiOff } from '@lucide/vue';
import { computed } from 'vue';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useRealtimeConnection } from '@/composables/useRealtime';
import { useCollapsed } from '@/composables/useShell';

/**
 * Says that live updates are not arriving, and nothing else.
 *
 * Realtime is an enhancement (ADR-0008): every screen works by asking the server, which is what
 * it does without a socket too. So this is a line of muted text rather than a banner — a person
 * whose connection dropped for two seconds does not need to be interrupted, and one whose
 * connection is gone should be able to see why the board stopped moving.
 *
 * Collapsed to an icon in the icon rail, because the sentence wraps to five lines in 56px. The
 * icon is never the whole message: the sentence stays in the tooltip and in the live region.
 */
const connection = useRealtimeConnection();
const collapsed = useCollapsed();

const label = computed<string | null>(() => (connection.value === 'offline' ? 'Live updates paused — reconnecting' : null));
</script>

<template>
    <p v-if="label !== null && !collapsed" class="px-2 py-1 text-xs text-chrome-muted-foreground" role="status" aria-live="polite">
        {{ label }}
    </p>

    <Tooltip v-else-if="label !== null">
        <TooltipTrigger class="flex h-8 w-full items-center justify-center text-chrome-muted-foreground" role="status" aria-live="polite">
            <WifiOff class="size-4" />
            <span class="sr-only">{{ label }}</span>
        </TooltipTrigger>
        <TooltipContent side="right">{{ label }}</TooltipContent>
    </Tooltip>
</template>
