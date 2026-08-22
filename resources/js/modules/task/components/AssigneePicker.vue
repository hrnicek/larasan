<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { TaskAssignee } from '@/modules/task/types';

/**
 * Who is on this task. The list is the server's — active workspace members — because a
 * client filtering a list it fetched would eventually filter it differently.
 */
const props = defineProps<{
    taskId: string;
    assignee: TaskAssignee | null;
    members: TaskAssignee[];
    editable: boolean;
}>();

const open = ref(false);
const saving = ref(false);

function assign(id: number | null): void {
    saving.value = true;

    router.put(
        TaskController.assign.url(props.taskId),
        { assignee_id: id },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = false;
                open.value = false;
            },
        },
    );
}
</script>

<template>
    <span v-if="!editable" class="text-xs text-muted-foreground">
        {{ assignee?.name ?? '—' }}
    </span>

    <DropdownMenu v-else v-model:open="open">
        <DropdownMenuTrigger
            class="rounded px-1 text-xs text-muted-foreground hover:text-foreground disabled:opacity-50"
            :disabled="saving"
            :aria-label="assignee ? `Assigned to ${assignee.name}` : 'Unassigned'"
        >
            {{ assignee?.name ?? 'Unassigned' }}
        </DropdownMenuTrigger>

        <DropdownMenuContent class="w-56" align="end">
            <DropdownMenuItem @select="assign(null)">Unassigned</DropdownMenuItem>
            <DropdownMenuItem
                v-for="member in members"
                :key="member.id"
                :class="member.id === assignee?.id ? 'bg-accent' : ''"
                @select="assign(member.id)"
            >
                {{ member.name }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
