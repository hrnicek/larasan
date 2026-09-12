<script setup lang="ts">
import { AtSign, Bell, Check, MessageSquare, UserPlus } from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import UserAvatar from '@/components/UserAvatar.vue';
import { accentDotClass, accentVars } from '@/lib/accentColor';
import { formatFeedTime, fullFeedTime } from '@/lib/feedTime';
import type { InboxNotification } from '@/modules/notification/types';

const props = defineProps<{
    notification: InboxNotification;
    active: boolean;
    arrived: boolean;
    timeStyle: 'relative' | 'clock';
}>();

const emit = defineEmits<{
    open: [notification: InboxNotification];
    markRead: [notification: InboxNotification];
}>();

const kinds: Record<InboxNotification['type'], { verb: string; icon: Component }> = {
    'task.assigned': { verb: 'assigned you', icon: UserPlus },
    'task.collaborator_added': { verb: 'added you as a collaborator on', icon: UserPlus },
    'comment.posted': { verb: 'commented on', icon: MessageSquare },
    'comment.mentioned': { verb: 'mentioned you on', icon: AtSign },
    unknown: { verb: 'did something about', icon: Bell },
};

const kind = computed(() => kinds[props.notification.type] ?? kinds.unknown);

const opens = computed<boolean>(() => Boolean(props.notification.subject?.url));
const lostAccess = computed<boolean>(() => props.notification.subject !== null && !opens.value);

const project = computed(() => props.notification.subject?.projects[0] ?? null);
const moreProjects = computed<number>(() => Math.max((props.notification.subject?.projects.length ?? 0) - 1, 0));

const time = computed<string>(() => {
    const at = props.notification.createdAt;

    if (at === null) {
        return '';
    }

    return props.timeStyle === 'clock'
        ? new Date(at).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
        : formatFeedTime(at);
});

const open = (): void => {
    if (opens.value) {
        emit('open', props.notification);
    }
};
</script>

<template>
    <li class="group/row relative" :class="{ 'inbox-arrived': arrived }" :data-inbox-id="notification.id">
        <component
            :is="opens ? 'button' : 'div'"
            :type="opens ? 'button' : undefined"
            tabindex="0"
            data-inbox-row
            :aria-current="active ? 'true' : undefined"
            class="flex w-full items-start gap-3 px-4 py-3 text-left transition-colors outline-none focus-visible:bg-accent md:px-6"
            :class="[opens ? 'cursor-pointer hover:bg-muted/50' : 'cursor-default', active ? 'bg-accent' : '']"
            @click="open"
        >
            <span
                class="mt-[11px] size-1.5 shrink-0 rounded-full transition-colors duration-200"
                :class="notification.read ? 'bg-transparent' : 'bg-primary'"
            />
            <span v-if="!notification.read" class="sr-only">Unread:</span>

            <span class="relative mt-px shrink-0">
                <UserAvatar v-if="notification.actor" :user="notification.actor" />
                <span v-else class="flex size-7 items-center justify-center rounded-md bg-muted text-muted-foreground">
                    <Bell class="size-3.5" aria-hidden="true" />
                </span>

                <span
                    class="absolute -right-1.5 -bottom-1.5 flex size-[18px] items-center justify-center rounded-full border border-border bg-card text-foreground/70"
                    aria-hidden="true"
                >
                    <component :is="kind.icon" class="size-3" :stroke-width="2.25" />
                </span>
            </span>

            <span class="flex min-w-0 flex-1 flex-col gap-1">
                <span class="text-sm leading-5" :class="notification.read ? 'text-muted-foreground' : 'text-foreground'">
                    <span :class="notification.read ? 'font-medium text-foreground/85' : 'font-semibold'">
                        {{ notification.actor?.name ?? 'Somebody' }}
                    </span>
                    {{ ` ${kind.verb} ` }}
                    <span
                        v-if="notification.subject"
                        :class="notification.read ? 'font-medium text-foreground/85' : 'font-semibold'"
                    >{{ notification.subject.title }}</span>
                    <span v-else class="italic">something that has since been removed</span>
                </span>

                <span
                    v-if="project || notification.excerpt || lostAccess"
                    class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5 text-xs leading-4 text-muted-foreground md:flex-nowrap"
                >
                    <span v-if="project" class="inline-flex max-w-full shrink-0 items-center gap-1.5 md:max-w-[45%]">
                        <span
                            class="size-2 shrink-0 rounded-[3px]"
                            :class="accentDotClass(project.color)"
                            :style="accentVars(project.color)"
                            aria-hidden="true"
                        />
                        <span class="truncate">{{ project.name }}</span>
                        <span v-if="moreProjects > 0" class="shrink-0 tabular-nums">+{{ moreProjects }}</span>
                    </span>

                    <span v-if="project && (notification.excerpt || lostAccess)" class="hidden shrink-0 text-border md:inline" aria-hidden="true">/</span>

                    <span v-if="notification.excerpt" class="w-full min-w-0 truncate md:w-auto">“{{ notification.excerpt }}”</span>
                    <span v-else-if="lostAccess" class="w-full truncate md:w-auto">You can no longer open this task</span>
                </span>
            </span>

            <time
                v-if="notification.createdAt"
                :datetime="notification.createdAt"
                :title="fullFeedTime(notification.createdAt)"
                class="shrink-0 pt-0.5 text-xs whitespace-nowrap tabular-nums"
                :class="[
                    notification.read ? 'text-muted-foreground/80' : 'font-medium text-foreground/70',
                    notification.read ? '' : 'md:group-focus-within/row:invisible md:group-hover/row:invisible',
                ]"
            >
                {{ time }}
            </time>
        </component>

        <!-- A sibling rather than a child, so it is not a button nested inside the row's button. -->
        <button
            v-if="!notification.read"
            type="button"
            class="absolute top-2 right-3 hidden size-8 items-center justify-center rounded-md text-muted-foreground opacity-0 transition hover:bg-background hover:text-foreground focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none group-focus-within/row:opacity-100 group-hover/row:opacity-100 md:right-4 md:flex"
            title="Mark as read (E)"
            aria-label="Mark as read"
            @click="emit('markRead', notification)"
        >
            <Check class="size-4" />
        </button>
    </li>
</template>

<style scoped>
.inbox-arrived > [data-inbox-row] {
    animation: inbox-arrived 2.4s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes inbox-arrived {
    from {
        background-color: var(--color-primary-subtle);
    }
}

@media (prefers-reduced-motion: reduce) {
    .inbox-arrived > [data-inbox-row] {
        animation: none;
    }
}
</style>
