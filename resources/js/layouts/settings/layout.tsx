import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Perfil',
        url: '/settings/profile',
        icon: null,
    },
    {
        title: 'Contraseña',
        url: '/settings/password',
        icon: null,
    },
    {
        title: 'Integración IA',
        url: '/settings/ia',
        icon: null,
    },
    {
        title: 'Instrucciones de Lore',
        url: '/settings/prompt',
        icon: null,
    },
    {
        title: 'Google Calendar',
        url: '/settings/calendar',
        icon: null,
    },
    {
        title: 'Días sin atención',
        url: '/settings/agenda',
        icon: null,
    },
    {
        title: 'Pagos',
        url: '/settings/pagos',
        icon: null,
    },
    {
        title: 'Equipo',
        url: '/settings/equipo',
        icon: null,
    },
    {
        title: 'Apariencia',
        url: '/settings/appearance',
        icon: null,
    },
];

export default function SettingsLayout({ children }: { children: React.ReactNode }) {
    const currentPath = window.location.pathname;
    const { auth } = usePage<SharedData>().props;
    const esPropietario = Boolean((auth?.user as { es_propietario?: boolean } | null)?.es_propietario);

    // Las "Instrucciones de Lore" solo las ve y edita la dueña (igual que Equipo,
    // que se valida en el servidor). Para el resto del equipo se oculta el enlace.
    const navItems = sidebarNavItems.filter((item) => item.url !== '/settings/prompt' || esPropietario);

    return (
        <div className="px-4 py-6">
            <Heading title="Configuración" description="Administra tu perfil, la integración con IA y las preferencias de la cuenta" />

            <div className="flex flex-col space-y-8 lg:flex-row lg:space-y-0 lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav className="flex flex-col space-y-1 space-x-0">
                        {navItems.map((item) => (
                            <Button
                                key={item.url}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start', {
                                    'bg-muted': currentPath === item.url,
                                })}
                            >
                                <Link href={item.url} prefetch>
                                    {item.title}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 md:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">{children}</section>
                </div>
            </div>
        </div>
    );
}
