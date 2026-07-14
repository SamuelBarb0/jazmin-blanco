import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { BookText, Bot, CalendarDays, Columns3, LayoutGrid, Megaphone, Sparkles, Users } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        url: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Pipeline',
        url: '/pipeline',
        icon: Columns3,
    },
    {
        title: 'Pacientes',
        url: '/leads',
        icon: Users,
    },
    {
        title: 'Agenda',
        url: '/appointments',
        icon: CalendarDays,
    },
    {
        title: 'Asistente',
        url: '/asistente',
        icon: Bot,
    },
    {
        title: 'Campañas',
        url: '/campaigns',
        icon: Megaphone,
    },
    {
        title: 'Servicios',
        url: '/services',
        icon: Sparkles,
    },
    {
        title: 'Conocimiento',
        url: '/knowledge',
        icon: BookText,
    },
];

const footerNavItems: NavItem[] = [];

export function AppSidebar() {
    const { setOpenMobile, isMobile } = useSidebar();
    const cleanup = useMobileNavigation();

    const handleNavigate = () => {
        cleanup();
        if (isMobile) {
            setOpenMobile(false);
        }
    };

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch onClick={handleNavigate}>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
