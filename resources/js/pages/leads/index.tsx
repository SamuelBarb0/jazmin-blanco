import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { channelMeta, colorOf, cop } from '@/lib/crm';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type Lead, type SharedData, type Stage } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { motion } from 'motion/react';
import { LayoutGrid, Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Pacientes', href: '/leads' }];

export default function LeadsIndex({ leads, stages }: { leads: Lead[]; stages: Stage[] }) {
    const { flash } = usePage<SharedData>().props;
    const [filter, setFilter] = useState<number | 'all'>('all');

    const filtered = useMemo(() => (filter === 'all' ? leads : leads.filter((l) => l.stage_id === filter)), [leads, filter]);

    const destroy = (lead: Lead) => {
        if (confirm(`¿Eliminar a "${lead.name}"?`)) {
            router.delete(route('leads.destroy', lead.id), { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pacientes" />
            <div className="flex h-full flex-1 flex-col gap-5 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-display text-3xl tracking-tight">Pacientes</h1>
                        <p className="text-muted-foreground">{leads.length} leads captados.</p>
                    </div>
                    <div className="flex gap-2">
                        <Button asChild variant="outline">
                            <Link href={route('pipeline')}>
                                <LayoutGrid className="h-4 w-4" /> Ver pipeline
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href={route('leads.create')}>
                                <Plus className="h-4 w-4" /> Nuevo lead
                            </Link>
                        </Button>
                    </div>
                </div>

                {flash?.success && (
                    <div className="rounded-lg border border-primary/30 bg-primary/10 px-4 py-3 text-sm font-medium text-primary">{flash.success}</div>
                )}

                {/* Filtros por etapa */}
                <div className="flex flex-wrap gap-2">
                    <FilterChip active={filter === 'all'} onClick={() => setFilter('all')} label="Todos" count={leads.length} />
                    {stages.map((s) => {
                        const count = leads.filter((l) => l.stage_id === s.id).length;
                        return <FilterChip key={s.id} active={filter === s.id} onClick={() => setFilter(s.id)} label={s.name} count={count} dot={colorOf(s.color).dot} />;
                    })}
                </div>

                {/* Tabla */}
                <div className="glass overflow-hidden rounded-2xl">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b border-border/60 text-left text-xs uppercase tracking-wide">
                                    <th className="px-3 py-3 font-medium sm:px-4">Paciente</th>
                                    <th className="hidden px-4 py-3 font-medium md:table-cell">Canal</th>
                                    <th className="px-3 py-3 font-medium sm:px-4">Etapa</th>
                                    <th className="hidden px-4 py-3 font-medium lg:table-cell">Etiquetas</th>
                                    <th className="hidden px-4 py-3 text-right font-medium sm:table-cell">Valor</th>
                                    <th className="px-3 py-3 sm:px-4"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {filtered.map((lead, i) => {
                                    const ch = channelMeta[lead.channel] ?? channelMeta.otro;
                                    const ChIcon = ch.icon;
                                    const stageColor = lead.stage ? colorOf(lead.stage.color) : colorOf('slate');
                                    return (
                                        <motion.tr
                                            key={lead.id}
                                            initial={{ opacity: 0, y: 6 }}
                                            animate={{ opacity: 1, y: 0 }}
                                            transition={{ duration: 0.3, delay: Math.min(i * 0.03, 0.4) }}
                                            className="hover:bg-muted/40 border-b border-border/40 transition-colors last:border-0"
                                        >
                                            <td className="px-3 py-3 sm:px-4">
                                                <Link href={route('leads.edit', lead.id)} className="font-medium hover:underline">
                                                    {lead.name}
                                                </Link>
                                                {lead.service_interest && <p className="text-muted-foreground text-xs">{lead.service_interest}</p>}
                                                {/* Canal + valor, visibles solo en móvil (las columnas están ocultas) */}
                                                <div className="text-muted-foreground mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs md:hidden">
                                                    <span className={cn('inline-flex items-center gap-1', ch.className)}>
                                                        <ChIcon className="size-3.5" /> {ch.label}
                                                    </span>
                                                    {cop(lead.value) && <span className="tabular-nums sm:hidden">· {cop(lead.value)}</span>}
                                                </div>
                                            </td>
                                            <td className="hidden px-4 py-3 md:table-cell">
                                                <span className={cn('inline-flex items-center gap-1.5', ch.className)}>
                                                    <ChIcon className="size-4" /> {ch.label}
                                                </span>
                                            </td>
                                            <td className="px-3 py-3 sm:px-4">
                                                {lead.stage && (
                                                    <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium', stageColor.soft, stageColor.text)}>
                                                        <span className={cn('size-1.5 rounded-full', stageColor.dot)} />
                                                        {lead.stage.name}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="hidden px-4 py-3 lg:table-cell">
                                                <div className="flex flex-wrap gap-1">
                                                    {(lead.tags ?? []).map((t) => {
                                                        const c = colorOf(t.color);
                                                        return (
                                                            <Badge key={t.id} variant="outline" className={cn('border-transparent text-[10px]', c.soft, c.text)}>
                                                                {t.name}
                                                            </Badge>
                                                        );
                                                    })}
                                                </div>
                                            </td>
                                            <td className="hidden px-4 py-3 text-right font-medium tabular-nums sm:table-cell">{cop(lead.value) ?? '—'}</td>
                                            <td className="px-3 py-3 sm:px-4">
                                                <div className="flex justify-end gap-1">
                                                    <Button asChild variant="ghost" size="icon" className="size-8">
                                                        <Link href={route('leads.edit', lead.id)}>
                                                            <Pencil className="size-4" />
                                                        </Link>
                                                    </Button>
                                                    <Button variant="ghost" size="icon" className="text-destructive hover:text-destructive size-8" onClick={() => destroy(lead)}>
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                </div>
                                            </td>
                                        </motion.tr>
                                    );
                                })}
                                {filtered.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="text-muted-foreground px-4 py-10 text-center text-sm">
                                            No hay leads en esta etapa.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

function FilterChip({ active, onClick, label, count, dot }: { active: boolean; onClick: () => void; label: string; count: number; dot?: string }) {
    return (
        <button
            onClick={onClick}
            className={cn(
                'inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition-colors',
                active ? 'border-primary/40 bg-primary/10 text-primary font-medium' : 'border-border/60 text-muted-foreground hover:bg-muted/50',
            )}
        >
            {dot && <span className={cn('size-2 rounded-full', dot)} />}
            {label}
            <span className="text-xs tabular-nums opacity-70">{count}</span>
        </button>
    );
}
