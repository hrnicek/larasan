<script setup lang="ts">
import { WifiOff } from '@lucide/vue';
import { computed } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useRealtimeConnection } from '@/composables/useRealtime';
import { useCollapsed } from '@/composables/useShell';

const connection = useRealtimeConnection();
const collapsed = useCollapsed();

const label = computed<string | null>(() =>
    connection.value === 'offline'
        ? 'Live updates paused — reconnecting'
        : null,
);
</script>

<template>
    <p
        v-if="label !== null && !collapsed"
        class="px-2 py-1 text-xs text-chrome-muted-foreground"
        role="status"
        aria-live="polite"
    >
        {{ label }}
    </p>

    <Tooltip v-else-if="label !== null">
        <TooltipTrigger
            class="flex h-8 w-full items-center justify-center text-chrome-muted-foreground"
            role="status"
            aria-live="polite"
        >
            <WifiOff class="size-4" />
            <span class="sr-only">{{ label }}</span>
        </TooltipTrigger>
        <TooltipContent side="right">{{ label }}</TooltipContent>
    </Tooltip>
</template>
