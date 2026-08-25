<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Check, CircleCheck, Ellipsis, Link2, Maximize2, PanelRightClose, Trash2 } from '@lucide/vue';
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
import { show } from '@/routes/tasks';

/**
 * The bar above a task: whether it is done, who is watching it, and every action that applies to
 * the task as a whole rather than to one of its fields.
 *
 * It is pinned, so completing a task never means scrolling back to the top for the control that
 * does it. The panel and the task's own page render the same bar and differ only in what a way
 * out means: the panel closes, the page has nowhere to close to.
 */
const props = defineProps<{
    detail: TaskDetail;
    variant: 'panel' | 'page';
    /**
     * Once the title has scrolled out of the body it is repeated here. A bar that says nothing
     * about which task it belongs to is a bar you have to scroll up to trust.
     */
    collapsed?: boolean;
}>();

const emit = defineEmits<{ close: []; deleted: [] }>();

const completed = (): boolean => props.detail.task.completedAt !== null;

function toggleCompletion(): void {
    if (!props.detail.can.update) {
        return;
    }

    /*
     * Called on the router rather than pulled off it. `router.put` extracted into a variable
     * loses its receiver, and Inertia's methods reach for `this` — which is a `Cannot read
     * properties of undefined (reading 'visit')` the moment somebody clicks, not at build time.
     */
    if (completed()) {
        router.delete(TaskController.reopen.url(props.detail.task.id), { preserveScroll: true });

        return;
    }

    router.put(TaskController.complete.url(props.detail.task.id), {}, { preserveScroll: true });
}

/**
 * The address of the task's own page, absolute, because a link is pasted somewhere this
 * application is not.
 */
async function copyLink(): Promise<void> {
    const url = `${window.location.origin}${show(props.detail.task.id).url}`;

    try {
        await navigator.clipboard.writeText(url);
        toast('Link copied.');
    } catch {
        // A browser refuses the clipboard outside a secure context, and a toast that lies about
        // it leaves somebody pasting whatever was there before.
        toast('Could not copy — the address is in the URL bar.');
    }
}

const deleting = ref(false);
const working = ref(false);

/*
 * `tasks.destroy` answers with `back()`, and back from an open panel is the same screen with
 * `?task=` still on it — the deleted task. The shell is told instead, because only it knows
 * whether the way out is closing a panel or leaving a page.
 */
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
        <!--
            Two states of the same control. Scrolled to the top it is the button that finishes the
            task; past the title it becomes the title, with the same circle in front of it.
        -->
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
