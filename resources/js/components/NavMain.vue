<script setup lang="ts">
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    items: NavItem[];
}>();

const page = usePage<SharedData>();

const currentPath = computed(() => page.url.split('?')[0]);

const normalizePath = (href?: string) => {
    if (!href) {
        return '';
    }

    try {
        return new URL(href).pathname;
    } catch {
        return href.split('?')[0];
    }
};

const isItemActive = (item: NavItem) => {
    if (item.disabled) {
        return false;
    }

    const itemPath = normalizePath(item.href);

    if (itemPath && (currentPath.value === itemPath || currentPath.value.startsWith(`${itemPath}/`))) {
        return true;
    }

    return item.items?.some(isItemActive) ?? false;
};

const navItems = computed(() => props.items);
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>Menu</SidebarGroupLabel>

        <SidebarMenu>
            <template v-for="item in navItems" :key="item.title">
                <SidebarMenuItem v-if="!item.items?.length">
                    <SidebarMenuButton
                        v-if="item.href && !item.disabled"
                        as-child
                        :is-active="isItemActive(item)"
                        :tooltip="item.title"
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" v-if="item.icon" />
                            <span>{{ item.title }}</span>
                        </Link>
                    </SidebarMenuButton>

                    <SidebarMenuButton v-else disabled :tooltip="item.title">
                        <component :is="item.icon" v-if="item.icon" />
                        <span>{{ item.title }}</span>
                    </SidebarMenuButton>
                </SidebarMenuItem>

                <Collapsible
                    v-else
                    as-child
                    :default-open="isItemActive(item)"
                    class="group/collapsible"
                >
                    <SidebarMenuItem>
                        <CollapsibleTrigger as-child>
                            <SidebarMenuButton :is-active="isItemActive(item)" :tooltip="item.title">
                                <component :is="item.icon" v-if="item.icon" />
                                <span>{{ item.title }}</span>
                                <ChevronRight
                                    class="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90"
                                />
                            </SidebarMenuButton>
                        </CollapsibleTrigger>

                        <CollapsibleContent>
                            <SidebarMenuSub>
                                <SidebarMenuSubItem v-for="subItem in item.items" :key="subItem.title">
                                    <SidebarMenuSubButton
                                        v-if="subItem.href && !subItem.disabled"
                                        as-child
                                        :is-active="isItemActive(subItem)"
                                    >
                                        <Link :href="subItem.href">
                                            <component :is="subItem.icon" v-if="subItem.icon" />
                                            <span>{{ subItem.title }}</span>
                                        </Link>
                                    </SidebarMenuSubButton>

                                    <SidebarMenuSubButton v-else aria-disabled="true" class="opacity-60">
                                        <component :is="subItem.icon" v-if="subItem.icon" />
                                        <span>{{ subItem.title }}</span>
                                        <span
                                            v-if="subItem.badge"
                                            class="ml-auto rounded-md bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground"
                                        >
                                            {{ subItem.badge }}
                                        </span>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            </SidebarMenuSub>
                        </CollapsibleContent>
                    </SidebarMenuItem>
                </Collapsible>
            </template>
        </SidebarMenu>
    </SidebarGroup>
</template>
