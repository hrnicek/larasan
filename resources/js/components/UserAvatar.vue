<script setup lang="ts">
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';

/**
 * Only what an avatar needs. Typed narrowly on purpose: a `User`, a workspace member and a task's
 * assignee are three different shapes in this application, and all three have a name and a face.
 */
const props = withDefaults(
    defineProps<{
        user: { name: string; avatar?: string | null };
        size?: 'xs' | 'sm' | 'md' | 'lg';
    }>(),
    {
        size: 'md',
    },
);

const { getInitials } = useInitials();

const showAvatar = computed(
    () => props.user.avatar && props.user.avatar !== '',
);

/**
 * `xs` exists for the calendar's chips, where a row of tasks shares the width of one day; `lg` for
 * the profile screen, where the face is the thing being edited.
 */
const box = computed<string>(
    () =>
        ({ xs: 'size-5', sm: 'size-6', md: 'size-7', lg: 'size-16' })[
            props.size
        ],
);

const initialsSize = computed<string>(
    () =>
        ({
            xs: 'text-[9px]',
            sm: 'text-[11px]',
            md: 'text-[11px]',
            lg: 'text-xl',
        })[props.size],
);
</script>

<template>
    <Avatar :class="`${box} ${size === 'lg' ? 'rounded-xl' : 'rounded-md'}`">
        <AvatarImage v-if="showAvatar" :src="user.avatar!" :alt="user.name" />
        <AvatarFallback
            class="bg-primary-subtle font-semibold text-primary-subtle-foreground"
            :class="[initialsSize, size === 'lg' ? 'rounded-xl' : 'rounded-md']"
        >
            {{ getInitials(user.name) }}
        </AvatarFallback>
    </Avatar>
</template>
