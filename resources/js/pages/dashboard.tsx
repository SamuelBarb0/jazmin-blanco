import { AnimatedNumber } from '@/components/ui/animated-number';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { LiquidCard } from '@/components/ui/liquid-card';
import { RadialGauge } from '@/components/ui/radial-gauge';
import { Sparkline } from '@/components/ui/sparkline';
import AppLayout from '@/layouts/app-layout';
import { channelMeta, colorOf } from '@/lib/crm';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type LeadChannel, type Service } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { motion, type Variants } from 'motion/react';
import { ArrowUpRight, BookOpen, Bot, CalendarCheck, Megaphone, Plus, Sparkles, TrendingDown, TrendingUp, Users } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Centro de comando', href: '/dashboard' }];

interface DashboardProps {
    metrics: { leads: number; leadsMonth: number; agendadas: number; cerradas: number; wonValue: number };
    pipeline: { name: string; count: number; color: string }[];
    channels: { key: LeadChannel; count: number; pct: number }[];
    servicesStats: { total: number; withAi: number };
    recentServices: Service[];
    aiConfigured: boolean;
}

const cop = (n: number) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n);

const container: Variants = { hidden: {}, show: { transition: { staggerChildren: 0.09, delayChildren: 0.06 } } };
const item: Variants = {
    hidden: { opacity: 0, y: 24, filter: 'blur(10px)' },
    show: { opacity: 1, y: 0, filter: 'blur(0px)', transition: { duration: 0.75, ease: [0.16, 1, 0.3, 1] } },
};

// Sparklines decorativas (tendencia ilustrativa).
const sparks = {
    leads: [820, 910, 880, 1040, 1120, 1080, 1240],
    agendadas: [41, 47, 52, 49, 63, 70, 78],
    cpla: [58, 55, 51, 49, 46, 44, 42],
    bot: [22, 19, 17, 14, 12, 10, 8],
};

export default function Dashboard({ metrics, pipeline, channels, servicesStats, recentServices, aiConfigured }: DashboardProps) {
    const maxPipeline = Math.max(1, ...pipeline.map((p) => p.count));

    const kpis = [
        { key: 'leads', label: 'Leads del mes', value: metrics.leadsMonth || metrics.leads, icon: Users, accent: 'text-chart-1', delta: 12.4, up: true, spark: sparks.leads, fmt: (n: number) => Math.round(n).toLocaleString('es-CO'), sample: false },
        { key: 'agendadas', label: 'Citas agendadas', value: metrics.agendadas, icon: CalendarCheck, accent: 'text-chart-3', delta: 9.1, up: true, spark: sparks.agendadas, fmt: (n: number) => Math.round(n).toString(), sample: false },
        { key: 'cpla', label: 'CPLA real', value: 42000, icon: Megaphone, accent: 'text-chart-4', delta: 6.2, up: false, spark: sparks.cpla, fmt: (n: number) => cop(Math.round(n / 1000) * 1000), sample: true },
        { key: 'bot', label: 'Resp. del bot', value: 8, icon: Bot, accent: 'text-chart-2', delta: 31, up: false, spark: sparks.bot, fmt: (n: number) => `${Math.round(n)}s`, sample: true },
    ];

    const channelBar: Record<string, string> = { whatsapp: 'bg-emerald-500', instagram: 'bg-pink-500', meta_ads: 'bg-sky-500' };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Centro de comando" />
            <motion.div variants={container} initial="hidden" animate="show" className="flex h-full flex-1 flex-col gap-5 p-4">
                {/* ── Hero ─────────────────────────────────────────────── */}
                <motion.div variants={item} className="grain glow relative overflow-hidden rounded-2xl border border-sidebar-border/60 bg-sidebar p-6 text-white sm:p-8">
                    <div className="aurora-mesh animate-float-slow absolute inset-0 opacity-90" />
                    <div className="relative z-10 flex flex-wrap items-end justify-between gap-6">
                        <div className="max-w-xl">
                            <div className="mb-3 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-medium backdrop-blur">
                                <span className="live-dot inline-block size-1.5 rounded-full text-emerald-400">
                                    <span className="block size-1.5 rounded-full bg-emerald-400" />
                                </span>
                                Bot Claude activo · 24/7
                            </div>
                            <h1 className="font-display text-3xl leading-[1.05] tracking-tight sm:text-[2.6rem]">
                                Buenos días, <span className="text-sky-300 italic">Dra. Jasmin</span>
                            </h1>
                            <p className="mt-2 max-w-md text-sm text-white/70">
                                Tienes <span className="font-semibold text-white">{metrics.leads} leads</span> en el pipeline y{' '}
                                <span className="font-semibold text-white">{metrics.agendadas}</span> citas agendadas esperando valoración.
                            </p>
                            <div className="mt-5 flex flex-wrap gap-2">
                                <Button asChild className="bg-white text-sidebar hover:bg-white/90">
                                    <Link href={route('leads.create')}>
                                        <Plus className="h-4 w-4" /> Nuevo lead
                                    </Link>
                                </Button>
                                <Button asChild variant="secondary" className="border border-white/15 bg-white/10 text-white hover:bg-white/20">
                                    <Link href={route('pipeline')}>Ver pipeline</Link>
                                </Button>
                            </div>
                        </div>
                        <div className="flex flex-col items-center text-center">
                            <span className="mb-2 text-[11px] font-medium tracking-wide text-white/55 uppercase">Tasa de agendamiento</span>
                            <RadialGauge value={metrics.leads ? Math.round((metrics.agendadas / metrics.leads) * 100) : 0} />
                            <span className="mt-2 inline-flex items-center gap-1 text-xs text-emerald-300">
                                <TrendingUp className="size-3" /> {metrics.cerradas} cerrados · {cop(metrics.wonValue)}
                            </span>
                        </div>
                    </div>
                </motion.div>

                {/* ── KPIs ─────────────────────────────────────────────── */}
                <motion.div variants={item} className="flex items-center justify-between px-1">
                    <h2 className="text-muted-foreground text-sm font-medium tracking-wide uppercase">Métricas</h2>
                    <Badge variant="outline" className="text-muted-foreground gap-1 text-[11px] font-normal">
                        <span className="size-1.5 rounded-full bg-emerald-500" /> En vivo
                    </Badge>
                </motion.div>

                <motion.div variants={container} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {kpis.map((k) => (
                        <LiquidCard key={k.key} variants={item} whileHover={{ y: -5 }} transition={{ type: 'spring', stiffness: 300, damping: 22 }} className="group relative overflow-hidden rounded-2xl p-5">
                            <div className="flex items-start justify-between">
                                <span className="text-muted-foreground flex items-center gap-1.5 text-sm">
                                    {k.label}
                                    {k.sample && <span className="text-muted-foreground/60 text-[10px]">· muestra</span>}
                                </span>
                                <span className={cn('bg-muted flex size-9 items-center justify-center rounded-lg', k.accent)}>
                                    <k.icon className="size-4.5" />
                                </span>
                            </div>
                            <div className="font-display mt-3 truncate text-3xl leading-none tracking-tight tabular-nums sm:text-4xl">
                                <AnimatedNumber value={k.value} format={k.fmt} />
                            </div>
                            <div className="mt-3 flex items-end justify-between gap-2">
                                <span className={cn('inline-flex items-center gap-1 text-xs font-medium', k.up ? 'text-emerald-600 dark:text-emerald-400' : 'text-sky-600 dark:text-sky-400')}>
                                    {k.up ? <TrendingUp className="size-3.5" /> : <TrendingDown className="size-3.5" />}
                                    {k.delta}%
                                </span>
                                <div className={cn('h-9 w-24', k.accent)}>
                                    <Sparkline data={k.spark} className="h-full w-full" />
                                </div>
                            </div>
                        </LiquidCard>
                    ))}
                </motion.div>

                {/* ── Conversión por canal + Pipeline ──────────────────── */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <motion.div variants={item} className="glass rounded-2xl p-6 lg:col-span-1">
                        <div className="mb-5 flex items-center justify-between">
                            <h3 className="font-medium">Conversión por canal</h3>
                            <Users className="text-muted-foreground size-4" />
                        </div>
                        <div className="space-y-5">
                            {channels.map((c, i) => {
                                const meta = channelMeta[c.key] ?? channelMeta.otro;
                                const Icon = meta.icon;
                                return (
                                    <div key={c.key}>
                                        <div className="mb-1.5 flex items-center justify-between text-sm">
                                            <span className={cn('inline-flex items-center gap-2', meta.className)}>
                                                <Icon className="size-4" /> {meta.label}
                                            </span>
                                            <span className="text-muted-foreground tabular-nums">
                                                {c.count} · {c.pct}%
                                            </span>
                                        </div>
                                        <div className="bg-muted h-2.5 overflow-hidden rounded-full">
                                            <motion.div
                                                className={cn('h-full rounded-full', channelBar[c.key] ?? 'bg-primary')}
                                                initial={{ width: 0 }}
                                                animate={{ width: `${c.pct}%` }}
                                                transition={{ duration: 1.1, delay: 0.3 + i * 0.12, ease: [0.16, 1, 0.3, 1] }}
                                            />
                                        </div>
                                    </div>
                                );
                            })}
                            {channels.every((c) => c.count === 0) && <p className="text-muted-foreground text-sm">Aún no hay leads por canal.</p>}
                        </div>
                    </motion.div>

                    {/* Pipeline real */}
                    <motion.div variants={item} className="glass rounded-2xl p-6 lg:col-span-2">
                        <div className="mb-5 flex items-center justify-between">
                            <div>
                                <h3 className="font-medium">Pipeline de pacientes</h3>
                                <p className="text-muted-foreground text-sm">Leads por etapa</p>
                            </div>
                            <Button asChild variant="ghost" size="sm">
                                <Link href={route('pipeline')}>
                                    Abrir tablero <ArrowUpRight className="size-4" />
                                </Link>
                            </Button>
                        </div>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                            {pipeline.map((p, i) => {
                                const c = colorOf(p.color);
                                return (
                                    <motion.div
                                        key={p.name}
                                        initial={{ opacity: 0, y: 12 }}
                                        animate={{ opacity: 1, y: 0 }}
                                        transition={{ duration: 0.5, delay: 0.25 + i * 0.06 }}
                                        className="bg-muted/40 flex flex-col gap-2 rounded-lg border border-border/60 p-3"
                                    >
                                        <span className="text-muted-foreground flex items-center gap-1.5 truncate text-xs">
                                            <span className={cn('size-1.5 rounded-full', c.dot)} /> {p.name}
                                        </span>
                                        <span className="font-display text-2xl leading-none">
                                            <AnimatedNumber value={p.count} />
                                        </span>
                                        <div className="bg-muted h-1 overflow-hidden rounded-full">
                                            <motion.div className={cn('h-full rounded-full', c.bar)} initial={{ width: 0 }} animate={{ width: `${(p.count / maxPipeline) * 100}%` }} transition={{ duration: 0.9, delay: 0.4 + i * 0.06 }} />
                                        </div>
                                    </motion.div>
                                );
                            })}
                        </div>
                    </motion.div>
                </div>

                {/* ── Base de conocimiento ─────────────────────────────── */}
                <motion.div variants={item} className="glass overflow-hidden rounded-2xl">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 p-5">
                        <div className="flex items-center gap-3">
                            <span className="bg-primary/10 text-primary flex size-10 items-center justify-center rounded-lg">
                                <BookOpen className="size-5" />
                            </span>
                            <div>
                                <h3 className="font-medium">Base de conocimiento del bot</h3>
                                <p className="text-muted-foreground text-sm">
                                    <span className="text-foreground font-semibold">{servicesStats.total}</span> servicios ·{' '}
                                    <span className="text-foreground font-semibold">{servicesStats.withAi}</span> con contexto IA
                                </p>
                            </div>
                        </div>
                        <Button asChild variant="ghost" size="sm">
                            <Link href={route('services.index')}>
                                Ver todos <ArrowUpRight className="size-4" />
                            </Link>
                        </Button>
                    </div>

                    {recentServices.length === 0 ? (
                        <div className="flex flex-col items-center gap-3 p-10 text-center">
                            <p className="text-muted-foreground text-sm">Aún no has cargado servicios. El bot necesita tu conocimiento para responder.</p>
                            <Button asChild size="sm">
                                <Link href={route('services.create')}>
                                    <Plus className="h-4 w-4" /> Crear primer servicio
                                </Link>
                            </Button>
                        </div>
                    ) : (
                        <ul className="divide-y divide-border/60">
                            {recentServices.map((service, i) => (
                                <motion.li
                                    key={service.id}
                                    initial={{ opacity: 0, x: -8 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    transition={{ duration: 0.4, delay: 0.1 + i * 0.06 }}
                                    className="hover:bg-muted/40 flex items-center justify-between gap-3 px-5 py-3.5 transition-colors"
                                >
                                    <div className="min-w-0">
                                        <Link href={route('services.edit', service.id)} className="font-medium hover:underline">
                                            {service.name}
                                        </Link>
                                        <p className="text-muted-foreground truncate text-sm">{service.short_description || 'Sin descripción'}</p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        {service.ai_context && (
                                            <Badge variant="outline" className="border-primary/40 text-primary gap-1">
                                                <Sparkles className="size-3" /> IA
                                            </Badge>
                                        )}
                                        {service.category && <Badge variant="outline">{service.category}</Badge>}
                                    </div>
                                </motion.li>
                            ))}
                        </ul>
                    )}
                </motion.div>

                {!aiConfigured && (
                    <motion.div variants={item} className="rounded-xl border border-amber-300/60 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-400">
                        El cerebro conversacional Claude aún no está activo. Pega tu API key en{' '}
                        <Link href={route('ai.edit')} className="font-medium underline">
                            Configuración → Integración IA
                        </Link>{' '}
                        para habilitarlo.
                    </motion.div>
                )}
            </motion.div>
        </AppLayout>
    );
}
