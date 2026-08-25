<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { CornerLeftUp } from '@lucide/vue';
import { computed } from 'vue';
import CustomFieldList from '@/modules/custom-field/components/CustomFieldList.vue';
import AttachmentList from '@/modules/file/components/AttachmentList.vue';
import TaskTags from '@/modules/tag/components/TaskTags.vue';
import ActivityFeed from '@/modules/task/components/ActivityFeed.vue';
import AssigneePicker from '@/modules/task/components/AssigneePicker.vue';
import DueDatePicker from '@/modules/task/components/DueDatePicker.vue';
import PriorityControl from '@/modules/task/components/PriorityControl.vue';
import SubtaskList from '@/modules/task/components/SubtaskList.vue';
import TaskProjectMemberships from '@/modules/task/components/TaskProjectMemberships.vue';
import TaskTextField from '@/modules/task/components/TaskTextField.vue';
import type { TaskAssignee, TaskDetail, TaskFeed } from '@/modules/task/types';

/**
 * The task itself — its title, its fields and everything under them.
 *
 * Separated from `TaskDetailPanel` so that the overlay panel and the task's own page render the
 * *same* component rather than two that drift, while the shell around it differs: one traps focus
 * and can be closed, the other is a page and has nowhere to close to.
 *
 * Two regions, and the difference between them is the point: the fields say what the task *is*
 * and sit on the canvas; the thread says what has been said about it and sits on its own surface
 * at the foot. The block draws its own horizontal padding rather than taking it from the shell,
 * because that surface has to reach the panel's edges.
 */
const props = defineProps<{
    detail: TaskDetail;
    /** Deferred: absent until the follow-up request lands (TASK-100-011). */
    activity?: TaskFeed;
    members: TaskAssignee[];
    priorities: string[];
}>();

const emit = defineEmits<{ open: [taskId: string] }>();

/** The composer's face. Shared by the shell, so it is the same person the topbar shows. */
const viewer = computed(() => {
    const user = usePage().props.auth.user;

    return user === null ? null : { name: user.name, avatar: user.avatar };
});

const fieldsEditable = computed<boolean>(() => props.detail.can.update);
</script>

<template>
    <div class="flex flex-col">
        <div class="flex flex-col gap-6 px-4 pt-4 pb-6 md:px-6 md:pt-5">
            <div class="flex flex-col gap-2">
                <!-- A subtask says whose it is before it says anything about itself. -->
                <Link
                    v-if="detail.task.parent"
                    :href="`/tasks/${detail.task.parent.id}`"
                    class="inline-flex w-fit items-center gap-1.5 text-xs text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                >
                    <CornerLeftUp class="size-3.5" aria-hidden="true" />
                    {{ detail.task.parent.title }}
                </Link>

                <TaskTextField
                    :task-id="detail.task.id"
                    field="title"
                    :value="detail.task.title"
                    :editable="fieldsEditable"
                    placeholder="Task name"
                    class="-mx-1.5"
                />
            </div>

            <!--
                One grid for every field, so the labels form a column and the values form a column.
                Four rows of `label: value` laid out one at a time drift apart by a few pixels each
                and the eye reads the drift before it reads the fields.
            -->
            <dl class="grid grid-cols-1 items-center gap-x-3 gap-y-1 md:grid-cols-[7.5rem_minmax(0,1fr)]">
                <dt class="text-[13px] text-muted-foreground">Assignee</dt>
                <dd class="flex min-h-9 items-center">
                    <!-- The same components the list row uses, not second copies of them. -->
                    <AssigneePicker
                        :task-id="detail.task.id"
                        :assignee="detail.task.assignee"
                        :members="members"
                        :editable="fieldsEditable"
                        variant="field"
                    />
                </dd>

                <dt class="text-[13px] text-muted-foreground">Due date</dt>
                <dd class="flex min-h-9 items-center">
                    <DueDatePicker
                        :task-id="detail.task.id"
                        :due-at="detail.task.dueAt"
                        :editable="fieldsEditable"
                        variant="field"
                    />
                </dd>

                <dt class="text-[13px] text-muted-foreground">Priority</dt>
                <dd class="flex min-h-9 items-center">
                    <PriorityControl
                        :task-id="detail.task.id"
                        :priority="detail.task.priority"
                        :priorities="priorities"
                        :editable="fieldsEditable"
                        variant="field"
                    />
                </dd>

                <dt class="text-[13px] text-muted-foreground">Tags</dt>
                <dd class="flex min-h-9 items-center">
                    <TaskTags
                        :task-id="detail.task.id"
                        :tags="detail.tags"
                        :available="detail.availableTags"
                        :editable="fieldsEditable"
                    />
                </dd>

                <dt class="text-[13px] text-muted-foreground">Created by</dt>
                <dd class="flex min-h-9 items-center">
                    <span class="px-1.5 text-sm">{{ detail.task.creator?.name ?? '—' }}</span>
                </dd>
            </dl>

            <TaskProjectMemberships
                :task-id="detail.task.id"
                :placements="detail.placements"
                :available-projects="detail.availableProjects"
                :editable="fieldsEditable"
            >
                <template #fields>
                    <CustomFieldList
                        :task-id="detail.task.id"
                        :fields="detail.customFields"
                        :editable="fieldsEditable"
                    />
                </template>
            </TaskProjectMemberships>

            <section class="flex flex-col gap-1">
                <h3 class="text-sm font-semibold">Description</h3>

                <TaskTextField
                    :task-id="detail.task.id"
                    field="description"
                    :value="detail.task.description"
                    :editable="fieldsEditable"
                    :multiline="true"
                    placeholder="What is this task about?"
                    class="-mx-2"
                />
            </section>

            <SubtaskList
                :parent-id="detail.task.id"
                :subtasks="detail.subtasks"
                :editable="fieldsEditable"
                @open="(taskId) => emit('open', taskId)"
            />

            <AttachmentList
                :task-id="detail.task.id"
                :attachments="detail.attachments"
                :can-attach="detail.can.attach"
            />
        </div>

        <ActivityFeed
            :task-id="detail.task.id"
            :feed="activity"
            :can-comment="detail.can.comment"
            :viewer="viewer"
        />
    </div>
</template>
