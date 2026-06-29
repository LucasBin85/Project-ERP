<script setup lang="ts">
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
    SidebarMenuItem } 
    from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { Home, BookOpen, Folder, LayoutGrid, Scale } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';
import { route } from 'ziggy-js'

const mainNavItems: NavItem[] = [
    {
        title: 'Home',
        href: route('financial-position.index'),
        icon: Home,
    },
    {
        title: 'Dashboard',
        href: route('dashboard'),
        icon: LayoutGrid,
    },
    {
        title: 'Plano de Contas',
        //href: '/chart-of-accounts',
        href: route('chart-of-accounts.index'),
        icon: Folder,
    },
    {
        title: 'Livro Diário',
        href: route('general-journal.index'),
        icon: BookOpen,
    },
    {
        title: 'Livro Razão',
        href: '/ledger',
        icon: Scale,
    },
    {
        title: 'Lançamentos',
        href: route('journal-entries.index'),
        //href: '/journal-entries',
        icon: Folder,
    },
    {
        title: 'Balancete',
        href: route('trial-balance.index'),
        icon: Scale,
    }

];

const footerNavItems: NavItem[] = [
    {
        title: 'Github Repo',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: Folder,
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
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
