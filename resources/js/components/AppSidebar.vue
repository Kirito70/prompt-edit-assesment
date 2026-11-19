<script setup lang="ts">
import { Link, usePage } from "@inertiajs/vue3";
import { History, LayoutGrid, ShieldCheck, Sparkles } from "@lucide/vue";
import { computed } from "vue";
import AppLogo from "@/components/AppLogo.vue";
import NavMain from "@/components/NavMain.vue";
import NavUser from "@/components/NavUser.vue";
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from "@/components/ui/sidebar";
import { dashboard, generate, history } from "@/routes";
import { dashboard as adminDashboard } from "@/routes/admin";
import type { NavSection } from "@/types";

const page = usePage();

const isAdmin = computed(() => page.props.auth.user.is_admin);

const sections = computed<NavSection[]>(() => {
    const list: NavSection[] = [
        {
            title: "Overview",
            items: [
                { title: "Dashboard", href: dashboard(), icon: LayoutGrid },
            ],
        },
        {
            title: "Images",
            items: [
                { title: "Generate", href: generate(), icon: Sparkles },
                { title: "History", href: history(), icon: History },
            ],
        },
    ];

    // The link is only a convenience: the server enforces who may open /admin.
    if (isAdmin.value) {
        list.push({
            title: "Administration",
            items: [
                {
                    title: "Admin dashboard",
                    href: adminDashboard(),
                    icon: ShieldCheck,
                },
            ],
        });
    }

    return list;
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain
                v-for="section in sections"
                :key="section.title"
                :title="section.title"
                :items="section.items"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
