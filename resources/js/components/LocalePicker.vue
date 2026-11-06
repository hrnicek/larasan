<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { t } from '@/lib/i18n';
import { update } from '@/routes/locale';

const page = usePage();

function choose(locale: string): void {
    if (locale === page.props.locale) {
        return;
    }

    router.put(
        update.url(),
        { locale },
        {
            preserveScroll: true,
            // Labels computed once in setup would keep the old language, so the page starts over.
            onSuccess: () => window.location.reload(),
        },
    );
}
</script>

<template>
    <div
        class="inline-flex gap-1 rounded-lg bg-muted p-1"
        role="radiogroup"
        :aria-label="t('Language')"
    >
        <button
            v-for="option in page.props.locales"
            :key="option.value"
            type="button"
            role="radio"
            :lang="option.value"
            :aria-checked="page.props.locale === option.value"
            :class="[
                'rounded-md px-3.5 py-1.5 text-sm transition-colors',
                page.props.locale === option.value
                    ? 'bg-card text-foreground shadow-xs'
                    : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
            ]"
            @click="choose(option.value)"
        >
            {{ option.label }}
        </button>
    </div>
</template>
