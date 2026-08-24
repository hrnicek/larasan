<script setup lang="ts">
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';

/**
 * Only what an avatar needs. Typed narrowly on purpose: a `User`, a workspace member and a task's
 * assignee are three different shapes in this application, and all three have a name and a face.
 */
const props = withDefaults(defineProps<{ user: { name: string; avatar?: string | null }; size?: 'sm' | 'md' }>(), {
    size: 'md',
});

const { getInitials } = useInitials();

const showAvatar = computed(() => props.user.avatar && props.user.avatar !== '');
</script>

<template>
    <Avatar :class="size === 'sm' ? 'size-6 rounded-md' : 'size-7 rounded-md'">
        <AvatarImage v-if="showAvatar" :src="user.avatar!" :alt="user.name" />
        <AvatarFallback class="rounded-md bg-primary-subtle text-[11px] font-semibold text-primary-subtle-foreground">
            {{ getInitials(user.name) }}
        </AvatarFallback>
    </Avatar>
</template>
