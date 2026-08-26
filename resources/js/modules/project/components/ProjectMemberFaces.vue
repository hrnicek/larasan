<script setup lang="ts">
import { computed } from 'vue';
import UserAvatar from '@/components/UserAvatar.vue';
import type { ProjectPerson } from '@/modules/project/types';

/**
 * Who is on this project, as a row of faces.
 *
 * The server sends five and the total; what is not drawn is a `+N`. Past five a stack stops being
 * a glance and becomes a queue, and the whole list is one click away in the dialog beside it.
 */
const props = defineProps<{
    members: ProjectPerson[];
    total: number;
}>();

const hidden = computed(() => Math.max(props.total - props.members.length, 0));
</script>

<template>
    <span v-if="props.members.length" class="flex -space-x-1.5" :aria-label="`${props.total} on this project`">
        <span v-for="member in props.members" :key="member.id" class="rounded-md ring-2 ring-background">
            <UserAvatar :user="{ name: member.name, avatar: member.avatar }" size="sm" />
        </span>

        <span
            v-if="hidden"
            class="bg-muted text-muted-foreground ring-background flex size-6 items-center justify-center rounded-md text-[10px] font-semibold ring-2"
        >
            +{{ hidden }}
        </span>
    </span>
</template>
