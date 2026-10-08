<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import UiThemePicker from '@/components/UiThemePicker.vue';
import type { UiTheme, UiThemeOption } from '@/types/ui';

defineProps<{
    uiTheme: UiTheme;
    uiThemes: UiThemeOption[];
}>();
</script>

<template>
    <Head title="Appearance" />

    <h1 class="sr-only">Appearance</h1>

    <div class="space-y-8">
        <div class="space-y-6">
            <Heading
                variant="small"
                title="Appearance"
                description="Light, dark, or whatever this device prefers"
            />
            <AppearanceTabs />
        </div>

        <div class="space-y-6">
            <Heading
                variant="small"
                title="Theme"
                description="What the surfaces are made of. Saved to your account, so it follows you to your next device."
            />
            <UiThemePicker :current="uiTheme" :themes="uiThemes" />
        </div>
    </div>
</template>
