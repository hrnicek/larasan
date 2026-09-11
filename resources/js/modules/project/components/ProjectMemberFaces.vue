<script setup lang="ts">
import { computed } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import UserAvatar from '@/components/UserAvatar.vue';
import type { ProjectPerson } from '@/modules/project/types';

/**
 * Who is on this project, as a row of faces.
 *
 * The server sends five and the total; what is not drawn is a `+N`. Past five a stack stops being
 * a glance and becomes a queue, and the whole list is one click away in the dialog beside it.
 *
 * The overlap stays inside an initial's margin. Two letters in a small face leave only a few
 * pixels either side, and a neighbour laid over more than that cuts the second letter off — a pile
 * of `C` and `D` names nobody. The name itself is on hover.
 */
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
