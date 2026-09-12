<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Check, CircleCheck, Ellipsis, Link2, Maximize2, PanelRightClose, Star, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import FollowerList from '@/modules/task/components/FollowerList.vue';
import type { TaskDetail } from '@/modules/task/types';
import { show, star, unstar } from '@/routes/tasks';

const props = defineProps<{
    detail: TaskDetail;
    variant: 'panel' | 'page';
    /** The title has scrolled out of view, so the bar shows it in place of the complete button. */
    collapsed?: boolean;
}>();

const emit = defineEmits<{ close: []; deleted: [] }>();

const completed = (): boolean => props.detail.task.completedAt !== null;

function toggleCompletion(): void {
    if (!props.detail.can.update) {
        return;
    }

    // Call methods on `router` directly; a detached `router.put` loses `this` and fails at runtime.
    if (completed()) {
        router.delete(TaskController.reopen.url(props.detail.task.id), { preserveScroll: true });

        return;
    }

    router.put(TaskController.complete.url(props.detail.task.id), {}, { preserveScroll: true });
}

async function copyLink(): Promise<void> {
    const url = `${window.location.origin}${show(props.detail.task.id).url}`;

    try {
        await navigator.clipboard.writeText(url);
        toast('Link copied.');
    } catch {
        // The Clipboard API is unavailable outside a secure context.
        toast('Could not copy — the address is in the URL bar.');
    }
}

function toggleStar(): void {
    if (props.detail.starred) {
        router.delete(unstar(props.detail.task.id).url, { preserveScroll: true });

        return;
    }

    router.post(star(props.detail.task.id).url, {}, { preserveScroll: true });
}

const deleting = ref(false);
const working = ref(false);

// `tasks.destroy` redirects back to the URL with `?task=`, so the shell decides how to leave.
function destroy(): void {
    working.value = true;

    router.delete(TaskController.destroy.url(props.detail.task.id), {
        preserveScroll: true,
        onSuccess: () => emit('deleted'),
        onFinish: () => {
            working.value = false;
            deleting.value = false;
        },
    });
}
</script>

<template>
    <div class="flex h-13 shrink-0 items-center gap-2 border-b border-border bg-background px-3">
        <button
            v-if="detail.can.update && !collapsed"
            type="button"
            class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-md border px-2.5 text-[13px] font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
            :class="
                completed()
                    ? 'border-emerald-600/40 bg-emerald-600/10 text-emerald-700 dark:text-emerald-300'
                    : 'border-border text-muted-foreground hover:bg-accent hover:text-foreground'
            "
            :aria-pressed="completed()"
            @click="toggleCompletion"
        >
            <Check class="size-4" aria-hidden="true" />
            {{ completed() ? 'Completed' : 'Mark complete' }}
        </button>

        <p v-else-if="collapsed" class="flex min-w-0 items-center gap-2">
            <CircleCheck
                class="size-4 shrink-0"
                :class="completed() ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'"
                aria-hidden="true"
            />
            <span class="truncate text-sm font-medium">{{ detail.task.title }}</span>
        </p>

        <span v-else class="text-sm text-muted-foreground">
            {{ completed() ? 'Completed' : 'Open' }}
        </span>

        <div class="ml-auto flex shrink-0 items-center gap-1">
            <FollowerList
                :task-id="detail.task.id"
                :followers="detail.followers"
                :following="detail.following"
            />

            <span class="mx-1 h-5 w-px bg-border" aria-hidden="true" />

            <Button
                variant="ghost"
                size="icon-sm"
                :class="detail.starred ? 'text-amber-500 dark:text-amber-400' : 'text-muted-foreground'"
                :aria-pressed="detail.starred"
                :aria-label="detail.starred ? 'Remove this task from starred' : 'Add this task to starred'"
                @click="toggleStar"
            >
                <Star class="size-4" :class="detail.starred && 'fill-current'" />
            </Button>

            <Button
                variant="ghost"
                size="icon-sm"
                class="text-muted-foreground"
                aria-label="Copy a link to this task"
                @click="copyLink"
            >
                <Link2 class="size-4" />
            </Button>

            <Button
                v-if="variant === 'panel'"
                as-child
                variant="ghost"
                size="icon-sm"
                class="text-muted-foreground"
            >
                <Link :href="show(detail.task.id)" aria-label="Open as a full page">
                    <Maximize2 class="size-4" />
                </Link>
            </Button>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button variant="ghost" size="icon-sm" class="text-muted-foreground" aria-label="More actions">
                        <Ellipsis class="size-4" />
                    </Button>
                </DropdownMenuTrigger>

                <DropdownMenuContent align="end" class="w-52">
                    <DropdownMenuItem @select="copyLink">
                        <Link2 class="size-4" />
                        Copy link
                    </DropdownMenuItem>

                    <template v-if="detail.can.delete">
                        <DropdownMenuSeparator />

                        <DropdownMenuItem variant="destructive" @select="deleting = true">
                            <Trash2 class="size-4" />
                            Delete task
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>

            <Button
                v-if="variant === 'panel'"
                variant="ghost"
                size="icon-sm"
                class="text-muted-foreground"
                aria-label="Close"
                @click="emit('close')"
            >
                <PanelRightClose class="size-4" />
            </Button>
        </div>

        <ConfirmDialog
            :open="deleting"
            title="Delete this task?"
            description="Its subtasks, comments and attachments go with it. This cannot be undone."
            confirm-label="Delete"
            cancel-label="Keep it"
            :pending="working"
            @update:open="(next) => (deleting = next)"
            @confirm="destroy"
        />
    </div>
</template>
