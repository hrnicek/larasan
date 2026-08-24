<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import CustomFieldList from '@/modules/custom-field/components/CustomFieldList.vue';
import AttachmentList from '@/modules/file/components/AttachmentList.vue';
import TaskTags from '@/modules/tag/components/TaskTags.vue';
import ActivityFeed from '@/modules/task/components/ActivityFeed.vue';
import AssigneePicker from '@/modules/task/components/AssigneePicker.vue';
import DueDatePicker from '@/modules/task/components/DueDatePicker.vue';
import FollowerList from '@/modules/task/components/FollowerList.vue';
import PriorityControl from '@/modules/task/components/PriorityControl.vue';
import SubtaskList from '@/modules/task/components/SubtaskList.vue';
import TaskProjectMemberships from '@/modules/task/components/TaskProjectMemberships.vue';
import TaskTextField from '@/modules/task/components/TaskTextField.vue';
import type { TaskAssignee, TaskDetail, TaskFeed } from '@/modules/task/types';

/**
 * The task itself — its title, its fields and everything under them.
 *
 * Separated from `TaskDetailPanel` so that the overlay panel and the task's own page render the
 * *same* component rather than two that drift, while the shell around it differs: one traps
 * focus and can be closed, the other is a page and has nowhere to close to.
 */
const props = defineProps<{
    detail: TaskDetail;
    /** Deferred: absent until the follow-up request lands (TASK-100-011). */
    activity?: TaskFeed;
    members: TaskAssignee[];
    priorities: string[];
}>();

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
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-col gap-2">
            <Link
                v-if="detail.task.parent"
                :href="`/tasks/${detail.task.parent.id}`"
                class="inline-flex w-fit items-center gap-1 text-xs text-muted-foreground transition-colors hover:text-foreground"
            >
                <span aria-hidden="true">↑</span>
                {{ detail.task.parent.title }}
            </Link>

            <button
                v-if="detail.can.update"
                type="button"
                class="inline-flex h-8 w-fit items-center gap-1.5 rounded-md border px-2.5 text-[13px] font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                :class="
                    completed()
                        ? 'border-emerald-600/40 bg-emerald-600/10 text-emerald-700 dark:text-emerald-300'
                        : 'border-border text-muted-foreground hover:bg-accent hover:text-foreground'
                "
                :aria-pressed="completed()"
                @click="toggleCompletion"
            >
                <Check class="size-4" />
                {{ completed() ? 'Completed' : 'Mark complete' }}
            </button>

            <TaskTextField
                :task-id="detail.task.id"
                field="title"
                :value="detail.task.title"
                :editable="detail.can.update"
                placeholder="Task name"
            />
        </header>

        <dl class="grid grid-cols-2 gap-2 text-sm">
            <div>
                <dt class="text-xs text-muted-foreground">Assignee</dt>
                <dd>
                    <!-- The same components the list row uses, not second copies of them. -->
                    <AssigneePicker
                        :task-id="detail.task.id"
                        :assignee="detail.task.assignee"
                        :members="members"
                        :editable="detail.can.update"
                        variant="field"
                    />
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Due</dt>
                <dd>
                    <DueDatePicker
                        :task-id="detail.task.id"
                        :due-at="detail.task.dueAt"
                        :editable="detail.can.update"
                    />
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Priority</dt>
                <dd>
                    <PriorityControl
                        :task-id="detail.task.id"
                        :priority="detail.task.priority"
                        :priorities="priorities"
                        :editable="detail.can.update"
                    />
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Created by</dt>
                <dd>{{ detail.task.creator?.name ?? '—' }}</dd>
            </div>
        </dl>

        <section>
            <h3 class="mb-1 text-xs text-muted-foreground">Description</h3>
            <TaskTextField
                :task-id="detail.task.id"
                field="description"
                :value="detail.task.description"
                :editable="detail.can.update"
                :multiline="true"
                placeholder="No description yet."
            />
        </section>

        <TaskProjectMemberships
            :task-id="detail.task.id"
            :placements="detail.placements"
            :available-projects="detail.availableProjects"
            :editable="detail.can.update"
        />

        <FollowerList
            :task-id="detail.task.id"
            :followers="detail.followers"
            :following="detail.following"
        />

        <SubtaskList
            :parent-id="detail.task.id"
            :subtasks="detail.subtasks"
            :editable="detail.can.update"
        />

        <CustomFieldList
            :task-id="detail.task.id"
            :fields="detail.customFields"
            :editable="detail.can.update"
        />

        <TaskTags
            :task-id="detail.task.id"
            :tags="detail.tags"
            :available="detail.availableTags"
            :editable="detail.can.update"
        />

        <AttachmentList
            :task-id="detail.task.id"
            :attachments="detail.attachments"
            :can-attach="detail.can.attach"
        />

        <ActivityFeed
            :task-id="detail.task.id"
            :feed="activity"
            :can-comment="detail.can.comment"
        />
    </div>
</template>
