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

/**
 * Priority, changed from the row. The options are the server's enum, sent with the page, so
 * a case added later appears here without a second list to remember.
 */
const props = defineProps<{
    taskId: string;
    priority: string;
    priorities: string[];
    editable: boolean;
}>();

const open = ref(false);
const saving = ref(false);

function change(priority: string): void {
    saving.value = true;

    router.put(
        TaskController.update.url(props.taskId),
        { priority },
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
    <span v-if="!editable" class="text-xs capitalize text-muted-foreground">{{ priority }}</span>

    <DropdownMenu v-else v-model:open="open">
        <DropdownMenuTrigger
            class="rounded px-1 text-xs capitalize text-muted-foreground hover:text-foreground disabled:opacity-50"
            :disabled="saving"
            aria-label="Priority"
        >
            {{ priority }}
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end">
            <DropdownMenuItem
                v-for="option in priorities"
                :key="option"
                class="capitalize"
                :class="option === priority ? 'bg-accent' : ''"
                @select="change(option)"
            >
                {{ option }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
