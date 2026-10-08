<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dashboard } from '@/routes';

const props = defineProps<{ status: number }>();

const titles: Record<number, string> = {
    403: 'You cannot open this',
    404: 'There is nothing here',
    500: 'Something broke on our side',
    503: 'Down for maintenance',
};

const descriptions: Record<number, string> = {
    403: 'This belongs to somebody else, or your access to it has ended.',
    404: 'The address is wrong, or what was here has been removed.',
    500: 'The error has been recorded. Nothing you did caused it.',
    503: 'We are working on it. Try again in a few minutes.',
};

const title = computed<string>(
    () => titles[props.status] ?? 'Something went wrong',
);
const description = computed<string>(
    () => descriptions[props.status] ?? 'The request could not be completed.',
);
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-4 p-6 text-center"
    >
        <Head :title="`${status}`" />

        <p class="text-sm font-medium text-muted-foreground">{{ status }}</p>
        <h1 class="text-2xl font-semibold tracking-tight">{{ title }}</h1>
        <p class="max-w-md text-sm text-muted-foreground">{{ description }}</p>

        <Link
            :href="dashboard()"
            class="text-sm font-medium underline underline-offset-4"
        >
            Back to the dashboard
        </Link>
    </div>
</template>
