<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { CornerLeftUp } from '@lucide/vue';
import { computed } from 'vue';
import CustomFieldList from '@/modules/custom-field/components/CustomFieldList.vue';
import AttachmentList from '@/modules/file/components/AttachmentList.vue';
import TaskTags from '@/modules/tag/components/TaskTags.vue';
import ActivityFeed from '@/modules/task/components/ActivityFeed.vue';
import AssigneePicker from '@/modules/task/components/AssigneePicker.vue';
import CollaboratorPicker from '@/modules/task/components/CollaboratorPicker.vue';
import DueDatePicker from '@/modules/task/components/DueDatePicker.vue';
import PriorityControl from '@/modules/task/components/PriorityControl.vue';
import SubtaskList from '@/modules/task/components/SubtaskList.vue';
import TaskDescriptionField from '@/modules/task/components/TaskDescriptionField.vue';
import TaskProjectMemberships from '@/modules/task/components/TaskProjectMemberships.vue';
import TaskTextField from '@/modules/task/components/TaskTextField.vue';
import type { TaskAssignee, TaskDetail, TaskFeed } from '@/modules/task/types';
import { show as showTask } from '@/routes/tasks';

const props = defineProps<{
    detail: TaskDetail;
    /** Deferred prop, undefined until loaded. */
    activity?: TaskFeed;
    members: TaskAssignee[];
    priorities: string[];
}>();

const emit = defineEmits<{ open: [taskId: string] }>();

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
                <Link
                    v-if="detail.task.parent"
                    :href="showTask(detail.task.parent.id)"
                    class="inline-flex w-fit items-center gap-1.5 text-xs text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                >
                    <CornerLeftUp class="size-3.5" aria-hidden="true" />
                    {{ detail.task.parent.title }}
                </Link>

                <TaskTextField
                    :task-id="detail.task.id"
                    :value="detail.task.title"
                    :editable="fieldsEditable"
                    placeholder="Task name"
                    class="-mx-1.5"
                />
            </div>

            <dl
                class="grid grid-cols-1 items-center gap-x-3 gap-y-1 md:grid-cols-[7.5rem_minmax(0,1fr)]"
            >
                <dt class="text-[13px] text-muted-foreground">Assignee</dt>
                <dd class="flex min-h-9 items-center">
                    <AssigneePicker
                        :task-id="detail.task.id"
                        :assignee="detail.task.assignee"
                        :members="members"
                        :editable="detail.can.assign"
                        variant="field"
                    />
                </dd>

                <dt class="text-[13px] text-muted-foreground">Collaborators</dt>
                <dd class="flex min-h-9 min-w-0 items-center">
                    <CollaboratorPicker
                        :task-id="detail.task.id"
                        :collaborators="detail.collaborators"
                        :assignee-id="detail.task.assignee?.id ?? null"
                        :members="members"
                        :editable="detail.can.assign"
                        :collaborating="detail.collaborating"
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
                        :can-create="detail.can.manageTags"
                    />
                </dd>

                <dt class="text-[13px] text-muted-foreground">Created by</dt>
                <dd class="flex min-h-9 items-center">
                    <span class="px-1.5 text-sm">{{
                        detail.task.creator?.name ?? '—'
                    }}</span>
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

                <TaskDescriptionField
                    :task-id="detail.task.id"
                    :value="detail.task.description"
                    :editable="fieldsEditable"
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
                :can-reorder="detail.can.update"
            />
        </div>

        <ActivityFeed
            :task-id="detail.task.id"
            :feed="activity"
            :can-comment="detail.can.comment"
            :viewer="viewer"
            :people="members"
        />
    </div>
</template>
