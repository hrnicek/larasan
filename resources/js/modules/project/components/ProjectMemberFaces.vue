<script setup lang="ts">
import { computed } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import UserAvatar from '@/components/UserAvatar.vue';
import type { ProjectPerson } from '@/modules/project/types';

const props = defineProps<{
    members: ProjectPerson[];
    total: number;
}>();

const hidden = computed(() => Math.max(props.total - props.members.length, 0));
</script>

<template>
    <span
        v-if="props.members.length"
        class="flex items-center -space-x-1"
        :aria-label="`${props.total} on this project`"
    >
        <Tooltip v-for="member in props.members" :key="member.id">
            <TooltipTrigger as-child>
                <span class="rounded-md ring-2 ring-background">
                    <UserAvatar
                        :user="{ name: member.name, avatar: member.avatar }"
                    />
                </span>
            </TooltipTrigger>
            <TooltipContent side="bottom">{{ member.name }}</TooltipContent>
        </Tooltip>

        <span
            v-if="hidden"
            class="flex size-7 items-center justify-center rounded-md bg-muted text-[11px] font-semibold text-muted-foreground ring-2 ring-background"
        >
            +{{ hidden }}
        </span>
    </span>
</template>
