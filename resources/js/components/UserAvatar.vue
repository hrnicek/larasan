<script setup lang="ts">
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import type { User } from '@/types';

const props = withDefaults(defineProps<{ user: User; size?: 'sm' | 'md' }>(), {
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
