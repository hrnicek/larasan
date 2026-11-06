<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import LocalePicker from '@/components/LocalePicker.vue';
import UiThemePicker from '@/components/UiThemePicker.vue';
import { t } from '@/lib/i18n';
import type { UiTheme, UiThemeOption } from '@/types/ui';

defineProps<{
    uiTheme: UiTheme;
    uiThemes: UiThemeOption[];
}>();
</script>

<template>
    <Head :title="t('Appearance')" />

    <h1 class="sr-only">{{ t('Appearance') }}</h1>

    <div class="space-y-8">
        <div class="space-y-6">
            <Heading
                variant="small"
                :title="t('Language')"
                :description="
                    t(
                        'The language of every screen and of the mail Larasan sends you.',
                    )
                "
            />
            <LocalePicker />
        </div>

        <div class="space-y-6">
            <Heading
                variant="small"
                :title="t('Appearance')"
                :description="t('Light, dark, or whatever this device prefers')"
            />
            <AppearanceTabs />
        </div>

        <div class="space-y-6">
            <Heading
                variant="small"
                :title="t('Theme')"
                :description="
                    t(
                        'What the surfaces are made of. Saved to your account, so it follows you to your next device.',
                    )
                "
            />
            <UiThemePicker :current="uiTheme" :themes="uiThemes" />
        </div>
    </div>
</template>
