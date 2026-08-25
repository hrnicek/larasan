<script setup lang="ts">
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';

/**
 * Only what an avatar needs. Typed narrowly on purpose: a `User`, a workspace member and a task's
 * assignee are three different shapes in this application, and all three have a name and a face.
 */
const props = withDefaults(defineProps<{ user: { name: string; avatar?: string | null }; size?: 'xs' | 'sm' | 'md' }>(), {
    size: 'md',
});

const { getInitials } = useInitials();

const showAvatar = computed(() => props.user.avatar && props.user.avatar !== '');

/** `xs` exists for the calendar's chips, where a row of tasks shares the width of one day. */
const box = computed<string>(() => ({ xs: 'size-5', sm: 'size-6', md: 'size-7' })[props.size]);
</script>

<template>
    <Avatar :class="`${box} rounded-md`">
        <AvatarImage v-if="showAvatar" :src="user.avatar!" :alt="user.name" />
        <AvatarFallback
            class="rounded-md bg-primary-subtle font-semibold text-primary-subtle-foreground"
            :class="size === 'xs' ? 'text-[9px]' : 'text-[11px]'"
        >
            {{ getInitials(user.name) }}
        </AvatarFallback>
    </Avatar>
</template>
