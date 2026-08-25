<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Bell, CheckSquare, Plus } from '@lucide/vue';
import { computed } from 'vue';
import InboxController from '@/actions/App/Http/Controllers/Notification/InboxController';
import MyTasksController from '@/actions/App/Http/Controllers/Task/MyTasksController';
import { accentDotClass } from '@/lib/accentColor';
import type { SidebarProject } from '@/modules/project/types';
import { create, show } from '@/routes/projects';

const page = usePage();

const user = computed(() => page.props.auth.user);
const workspace = computed(() => page.props.workspace);
const projects = computed<SidebarProject[]>(() => page.props.projects);
const unread = computed<number>(() => page.props.unreadNotifications);
const canCreate = computed<boolean>(() => page.props.auth.capabilities.includes('project.create'));

/** The greeting reads the visitor's own clock; the server has no business knowing their timezone. */
const greeting = computed<string>(() => {
    const hour = new Date().getHours();

    if (hour < 12) {
        return 'Good morning';
    }

    return hour < 18 ? 'Good afternoon' : 'Good evening';
});
</script>

<template>
    <Head title="Home" />

    <div class="mx-auto w-full max-w-4xl px-4 py-8 md:px-6 md:py-12">
        <header class="mb-8">
            <p class="text-sm text-muted-foreground">{{ workspace?.name }}</p>
            <h1 class="text-2xl font-semibold tracking-tight">{{ greeting }}, {{ user?.name }}</h1>
        </header>

        <div class="mb-8 grid gap-3 sm:grid-cols-2">
            <Link
                :href="MyTasksController.index.url()"
                class="group flex items-center gap-3 rounded-md border border-border p-4 transition-colors hover:border-primary/40 hover:bg-primary-subtle focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
            >
                <CheckSquare class="size-5 shrink-0 text-primary" />
                <span class="text-sm font-medium">My Tasks</span>
                <ArrowRight class="ml-auto size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
            </Link>

            <Link
                :href="InboxController.index.url()"
                class="group flex items-center gap-3 rounded-md border border-border p-4 transition-colors hover:border-primary/40 hover:bg-primary-subtle focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
            >
                <Bell class="size-5 shrink-0 text-primary" />
                <span class="text-sm font-medium">Inbox</span>
                <span v-if="unread" class="rounded-full bg-primary px-2 py-0.5 text-[11px] font-semibold text-primary-foreground">
                    {{ unread > 99 ? '99+' : unread }} unread
                </span>
                <ArrowRight class="ml-auto size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
            </Link>
        </div>

        <section>
            <h2 class="mb-3 text-[13px] font-semibold tracking-wide text-muted-foreground uppercase">Projects</h2>

            <ul v-if="projects.length" class="grid gap-2 sm:grid-cols-2">
                <li v-for="project in projects" :key="project.id">
                    <Link
                        :href="show(project.id).url"
                        class="flex items-center gap-2.5 rounded-md border border-border px-3 py-2.5 text-sm transition-colors hover:border-primary/40 hover:bg-primary-subtle focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    >
                        <span class="size-2.5 shrink-0 rounded-[3px]" :class="accentDotClass(project.color)" />
                        <span class="truncate">{{ project.name }}</span>
                    </Link>
                </li>
            </ul>

            <!-- One concrete next action rather than an apology about emptiness. -->
            <div v-else class="rounded-md border border-dashed border-border p-8 text-center">
                <p class="text-sm text-muted-foreground">Nothing is running in this workspace yet.</p>
                <Link
                    v-if="canCreate"
                    :href="create()"
                    class="mt-4 inline-flex h-9 items-center gap-1.5 rounded-md bg-primary px-3 text-[13px] font-semibold text-primary-foreground transition-colors hover:bg-primary-hover focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                >
                    <Plus class="size-4" />
                    Create the first project
                </Link>
                <p v-else class="mt-2 text-sm text-muted-foreground">An admin can add you to one.</p>
            </div>
        </section>
    </div>
</template>
