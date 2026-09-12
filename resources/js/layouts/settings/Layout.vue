<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { usePendingSkeleton } from '@/composables/usePendingScreen';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { index as fields } from '@/routes/custom-fields';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as tags } from '@/routes/tags';
import { edit as editWorkspace, members } from '@/routes/workspaces';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: editProfile(),
        component: 'settings/Profile',
    },
    {
        title: 'Workspace',
        href: editWorkspace(),
        component: 'settings/Workspace',
    },
    {
        title: 'Members',
        href: members(),
        component: 'settings/Members',
    },
    {
        title: 'Fields',
        href: fields(),
        component: 'settings/Fields',
    },
    {
        title: 'Tags',
        href: tags(),
        component: 'settings/Tags',
    },
    {
        title: 'Security',
        href: editSecurity(),
        component: 'settings/Security',
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        component: 'settings/Appearance',
    },
];

const { isCurrentOrParentUrl } = useCurrentUrl();

const skeleton = usePendingSkeleton('settings');
</script>

<template>
    <div class="px-4 py-6">
        <Heading
            title="Settings"
            description="You, this workspace, and how the application looks"
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-48">
                <nav
                    class="flex flex-row gap-1 overflow-x-auto pb-2 md:flex-col md:gap-0 md:space-y-1 md:overflow-x-visible md:pb-0"
                    aria-label="Settings"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'shrink-0 justify-start md:w-full',
                            { 'bg-muted': isCurrentOrParentUrl(item.href) },
                        ]"
                        as-child
                    >
                        <Link
                            :href="item.href"
                            :component="isCurrentOrParentUrl(item.href) ? undefined : item.component"
                            :prefetch="isCurrentOrParentUrl(item.href) ? false : 'click'"
                        >
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="flex-1 md:max-w-2xl">
                <section class="max-w-xl space-y-12">
                    <div v-if="skeleton" data-screen-pending aria-busy="true">
                        <p class="sr-only" role="status">Loading…</p>

                        <component :is="skeleton" />
                    </div>

                    <slot v-else />
                </section>
            </div>
        </div>
    </div>
</template>
