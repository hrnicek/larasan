<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { UserPlus } from '@lucide/vue';
import { computed, ref } from 'vue';
import TaskFollowerController from '@/actions/App/Http/Controllers/Task/TaskFollowerController';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import UserAvatar from '@/components/UserAvatar.vue';
import type { TaskAssignee } from '@/modules/task/types';

const props = defineProps<{
    taskId: string;
    followers: TaskAssignee[];
    following: boolean;
}>();

const shown = computed<TaskAssignee[]>(() => props.followers.slice(0, 3));
const hidden = computed<number>(() =>
    Math.max(props.followers.length - shown.value.length, 0),
);

const working = ref(false);

const toggle = (): void => {
    working.value = true;

    const options = {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            working.value = false;
        },
    };

    if (props.following) {
        router.delete(
            TaskFollowerController.destroy.url(props.taskId),
            options,
        );

        return;
    }

    router.post(TaskFollowerController.store.url(props.taskId), {}, options);
};
</script>

<template>
    <div class="flex items-center gap-1">
        <Popover v-if="followers.length">
            <PopoverTrigger
                class="flex items-center rounded-md pr-1 transition-opacity hover:opacity-80 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                :aria-label="`${followers.length} watching this task`"
            >
                <span class="flex -space-x-1.5">
                    <span
                        v-for="follower in shown"
                        :key="follower.id"
                        class="rounded-md ring-2 ring-background"
                    >
                        <UserAvatar :user="follower" size="sm" />
                    </span>

                    <span
                        v-if="hidden"
                        class="flex size-6 items-center justify-center rounded-md bg-muted text-[10px] font-semibold text-muted-foreground ring-2 ring-background"
                    >
                        +{{ hidden }}
                    </span>
                </span>
            </PopoverTrigger>

            <PopoverContent align="end" class="w-64 p-0">
                <p
                    class="border-b border-border px-3 py-2 text-xs font-medium text-muted-foreground"
                >
                    Watching this task
                </p>

                <ul class="max-h-56 overflow-y-auto p-1">
                    <li
                        v-for="follower in followers"
                        :key="follower.id"
                        class="flex items-center gap-2 rounded-md px-2 py-1.5"
                    >
                        <UserAvatar :user="follower" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm">{{
                                follower.name
                            }}</span>
                            <span
                                v-if="follower.email"
                                class="block truncate text-xs text-muted-foreground"
                                >{{ follower.email }}</span
                            >
                        </span>
                    </li>
                </ul>

                <div class="border-t border-border p-1">
                    <Button
                        variant="ghost"
                        size="sm"
                        class="w-full justify-start text-xs"
                        :disabled="working"
                        :aria-pressed="following"
                        @click="toggle"
                    >
                        {{ following ? 'Stop watching' : 'Watch this task' }}
                    </Button>
                </div>
            </PopoverContent>
        </Popover>

        <Button
            v-if="!following"
            variant="ghost"
            size="icon-sm"
            class="size-6 border border-dashed border-muted-foreground/50 text-muted-foreground"
            :disabled="working"
            aria-label="Watch this task"
            @click="toggle"
        >
            <UserPlus class="size-3.5" />
        </Button>
    </div>
</template>
