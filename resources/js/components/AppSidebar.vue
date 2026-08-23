<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { BookOpen, Bell, CheckSquare, FolderGit2, LayoutGrid, Search as SearchIcon } from '@lucide/vue';
import { computed } from 'vue';
import InboxController from '@/actions/App/Http/Controllers/Notification/InboxController';
import SearchController from '@/actions/App/Http/Controllers/Search/SearchController';
import MyTasksController from '@/actions/App/Http/Controllers/Task/MyTasksController';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import ProjectNavList from '@/modules/project/components/ProjectNavList.vue';
import WorkspaceSwitcher from '@/modules/workspace/components/WorkspaceSwitcher.vue';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const page = usePage();

/** The badge is the server's count, shared with every screen (TASK-130-009). */
const unread = computed<number>(() => page.props.unreadNotifications);

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'My Tasks',
        href: MyTasksController.index.url(),
        icon: CheckSquare,
    },
    {
        title: 'Inbox',
        href: InboxController.index.url(),
        icon: Bell,
    },
    {
        title: 'Search',
        href: SearchController.index.url(),
        icon: SearchIcon,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <WorkspaceSwitcher />

            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" :badges="{ Inbox: unread }" />
            <ProjectNavList />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
