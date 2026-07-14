import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { LiquidCard } from '@/components/ui/liquid-card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Service, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { motion, type Variants } from 'motion/react';
import { Clock, Pencil, Plus, Sparkles, Trash2 } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Servicios', href: '/services' }];

const container: Variants = { hidden: {}, show: { transition: { staggerChildren: 0.08, delayChildren: 0.05 } } };
const item: Variants = {
    hidden: { opacity: 0, y: 20, filter: 'blur(8px)' },
    show: { opacity: 1, y: 0, filter: 'blur(0px)', transition: { duration: 0.65, ease: [0.16, 1, 0.3, 1] } },
};

function formatPrice(price: string | null): string | null {
    if (!price) return null;
    const value = Number(price);
    if (Number.isNaN(value)) return null;
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(value);
}

export default function ServicesIndex({ services }: { services: Service[] }) {
    const { flash } = usePage<SharedData>().props;

    const destroy = (service: Service) => {
        if (confirm(`¿Eliminar el servicio "${service.name}"? Esta acción no se puede deshacer.`)) {
            router.delete(route('services.destroy', service.id), { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Servicios" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-display text-3xl tracking-tight">Servicios</h1>
                        <p className="text-muted-foreground">La base de conocimiento que alimenta al bot.</p>
                    </div>
                    <Button asChild>
                        <Link href={route('services.create')}>
                            <Plus className="h-4 w-4" />
                            Nuevo servicio
                        </Link>
                    </Button>
                </div>

                {flash?.success && (
                    <div className="rounded-lg border border-primary/30 bg-primary/10 px-4 py-3 text-sm font-medium text-primary">{flash.success}</div>
                )}

                {services.length === 0 ? (
                    <div className="flex flex-1 flex-col items-center justify-center gap-4 rounded-xl border border-dashed border-sidebar-border/70 p-6 text-center sm:p-12">
                        <div className="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-full">
                            <Sparkles className="size-7" />
                        </div>
                        <div>
                            <h2 className="text-lg font-medium">Aún no hay servicios</h2>
                            <p className="text-muted-foreground">Crea tu primer servicio y genera su contexto con IA.</p>
                        </div>
                        <Button asChild>
                            <Link href={route('services.create')}>
                                <Plus className="h-4 w-4" />
                                Crear servicio
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <motion.div variants={container} initial="hidden" animate="show" className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {services.map((service) => (
                            <LiquidCard
                                key={service.id}
                                variants={item}
                                whileHover={{ y: -5 }}
                                transition={{ type: 'spring', stiffness: 300, damping: 22 }}
                                className="flex h-full flex-col rounded-2xl"
                            >
                                <CardHeader>
                                    <div className="flex items-start justify-between gap-2">
                                        <CardTitle className="leading-tight">{service.name}</CardTitle>
                                        {!service.is_active && <Badge variant="secondary">Inactivo</Badge>}
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2 pt-1">
                                        {service.category && <Badge variant="outline">{service.category}</Badge>}
                                        {service.ai_context && (
                                            <Badge variant="outline" className="gap-1 border-primary/40 text-primary">
                                                <Sparkles className="size-3" /> IA
                                            </Badge>
                                        )}
                                    </div>
                                </CardHeader>
                                <CardContent className="flex-1">
                                    <p className="text-muted-foreground line-clamp-3 text-sm">
                                        {service.short_description || service.ai_context || 'Sin descripción.'}
                                    </p>
                                    <div className="text-muted-foreground mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                                        {formatPrice(service.price) && <span className="font-medium text-foreground">{formatPrice(service.price)}</span>}
                                        {service.duration_minutes ? (
                                            <span className="inline-flex items-center gap-1">
                                                <Clock className="size-3.5" />
                                                {service.duration_minutes} min
                                            </span>
                                        ) : null}
                                    </div>
                                </CardContent>
                                <CardFooter className="gap-2">
                                    <Button asChild variant="outline" size="sm">
                                        <Link href={route('services.edit', service.id)}>
                                            <Pencil className="h-4 w-4" />
                                            Editar
                                        </Link>
                                    </Button>
                                    <Button variant="ghost" size="sm" className="text-destructive hover:text-destructive" onClick={() => destroy(service)}>
                                        <Trash2 className="h-4 w-4" />
                                    </Button>
                                </CardFooter>
                            </LiquidCard>
                        ))}
                    </motion.div>
                )}
            </div>
        </AppLayout>
    );
}
