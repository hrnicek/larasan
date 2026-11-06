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
import { t } from '@/lib/i18n';

const props = defineProps<{
    taskId: string;
    priority: string;
    priorities: string[];
    editable: boolean;
    variant?: 'inline' | 'field';
}>();

const labelOf = (value: string): string => {
    const labels: Record<string, string> = {
        low: t('Low'),
        medium: t('Medium'),
        high: t('High'),
        urgent: t('Urgent'),
    };

    return labels[value] ?? value;
};

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
    <span
        v-if="!editable"
        class="text-muted-foreground capitalize"
        :class="variant === 'field' ? 'text-sm' : 'text-xs'"
        >{{ labelOf(priority) }}</span
    >

    <DropdownMenu v-else v-model:open="open">
        <DropdownMenuTrigger
            class="inline-flex min-h-11 items-center rounded-md text-muted-foreground capitalize transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none disabled:opacity-50 md:min-h-6"
            :class="
                variant === 'field'
                    ? 'px-1.5 py-1 text-sm hover:bg-accent md:min-h-8'
                    : 'px-1 text-xs'
            "
            :disabled="saving"
            :aria-label="
                t('Priority: :priority', { priority: labelOf(priority) })
            "
        >
            {{ labelOf(priority) }}
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end">
            <DropdownMenuItem
                v-for="option in priorities"
                :key="option"
                class="capitalize"
                :class="option === priority ? 'bg-accent' : ''"
                @select="change(option)"
            >
                {{ labelOf(option) }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
