import { AnimatedNumber } from '@/components/ui/animated-number';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { channelMeta, colorOf, cop } from '@/lib/crm';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type Lead, type Stage } from '@/types';
import {
    DndContext,
    DragOverlay,
    PointerSensor,
    closestCorners,
    useDraggable,
    useDroppable,
    useSensor,
    useSensors,
    type DragEndEvent,
    type DragStartEvent,
} from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import { Head, Link, router } from '@inertiajs/react';
import { AnimatePresence, motion } from 'motion/react';
import { Pencil, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Pipeline', href: '/pipeline' }];

function LeadCard({ lead, dragging = false }: { lead: Lead; dragging?: boolean }) {
    const channel = channelMeta[lead.channel] ?? channelMeta.otro;
    const ChannelIcon = channel.icon;
    const value = cop(lead.value);

    return (
        <div
            className={cn(
                'liquid-glass group/card rounded-xl p-3.5 select-none',
                dragging ? 'rotate-[2deg] scale-[1.03] shadow-2xl' : '',
            )}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="truncate font-medium leading-tight">{lead.name}</p>
                    {lead.service_interest && <p className="text-muted-foreground truncate text-xs">{lead.service_interest}</p>}
                </div>
                <Link
                    href={route('leads.edit', lead.id)}
                    onPointerDown={(e) => e.stopPropagation()}
                    className="text-muted-foreground hover:text-foreground -mt-1 -mr-1 rounded-md p-1 opacity-0 transition group-hover/card:opacity-100"
                >
                    <Pencil className="size-3.5" />
                </Link>
            </div>

            {lead.tags && lead.tags.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1">
                    {lead.tags.map((t) => {
                        const c = colorOf(t.color);
                        return (
                            <span key={t.id} className={cn('inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium', c.soft, c.text)}>
                                {t.name}
                            </span>
                        );
                    })}
                </div>
            )}

            <div className="mt-2.5 flex items-center justify-between">
                <span className={cn('inline-flex items-center gap-1 text-xs', channel.className)}>
                    <ChannelIcon className="size-3.5" />
                    {channel.label}
                </span>
                {value && <span className="text-foreground text-xs font-semibold tabular-nums">{value}</span>}
            </div>
        </div>
    );
}

function DraggableLead({ lead, stageId }: { lead: Lead; stageId: number }) {
    const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({
        id: `lead-${lead.id}`,
        data: { fromStage: stageId },
    });

    return (
        <div
            ref={setNodeRef}
            style={{ transform: CSS.Translate.toString(transform), opacity: isDragging ? 0.35 : 1 }}
            {...listeners}
            {...attributes}
            className="cursor-grab touch-none active:cursor-grabbing"
        >
            <LeadCard lead={lead} />
        </div>
    );
}

function Column({ stage }: { stage: Stage }) {
    const { setNodeRef, isOver } = useDroppable({ id: `stage-${stage.id}` });
    const c = colorOf(stage.color);
    const leads = stage.leads ?? [];
    const total = leads.reduce((sum, l) => sum + Number(l.value ?? 0), 0);

    return (
        <div className="flex w-72 shrink-0 flex-col">
            <div className="mb-2 flex items-center justify-between px-1">
                <div className="flex items-center gap-2">
                    <span className={cn('size-2.5 rounded-full', c.dot)} />
                    <span className="font-medium">{stage.name}</span>
                    <span className="text-muted-foreground bg-muted rounded-full px-2 py-0.5 text-xs tabular-nums">{leads.length}</span>
                </div>
                {total > 0 && <span className="text-muted-foreground text-xs tabular-nums">{cop(total)}</span>}
            </div>

            <div
                ref={setNodeRef}
                className={cn(
                    'flex min-h-[120px] flex-1 flex-col gap-2.5 rounded-2xl border border-dashed p-2.5 transition-colors',
                    isOver ? cn(c.soft, c.border) : 'border-border/60 bg-muted/30',
                )}
            >
                <AnimatePresence initial={false}>
                    {leads.map((lead) => (
                        <motion.div
                            key={lead.id}
                            layout
                            initial={{ opacity: 0, scale: 0.96 }}
                            animate={{ opacity: 1, scale: 1 }}
                            exit={{ opacity: 0, scale: 0.96 }}
                            transition={{ duration: 0.18 }}
                        >
                            <DraggableLead lead={lead} stageId={stage.id} />
                        </motion.div>
                    ))}
                </AnimatePresence>
                {leads.length === 0 && <p className="text-muted-foreground/60 py-6 text-center text-xs">Arrastra leads aquí</p>}
            </div>
        </div>
    );
}

export default function PipelineBoard({ stages: initial }: { stages: Stage[] }) {
    const [stages, setStages] = useState<Stage[]>(initial);
    const [activeLead, setActiveLead] = useState<Lead | null>(null);

    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }));

    const totalLeads = useMemo(() => stages.reduce((n, s) => n + (s.leads?.length ?? 0), 0), [stages]);

    const findLead = (id: number): Lead | undefined => stages.flatMap((s) => s.leads ?? []).find((l) => l.id === id);

    const onDragStart = (e: DragStartEvent) => {
        const id = Number(String(e.active.id).replace('lead-', ''));
        setActiveLead(findLead(id) ?? null);
    };

    const onDragEnd = (e: DragEndEvent) => {
        setActiveLead(null);
        const { active, over } = e;
        if (!over) return;

        const leadId = Number(String(active.id).replace('lead-', ''));
        const toStageId = Number(String(over.id).replace('stage-', ''));
        const fromStageId = active.data.current?.fromStage as number | undefined;
        if (!toStageId || toStageId === fromStageId) return;

        const lead = findLead(leadId);
        if (!lead) return;

        // Actualización optimista: mover el lead a la columna destino.
        const next = stages.map((s) => {
            if (s.id === fromStageId) return { ...s, leads: (s.leads ?? []).filter((l) => l.id !== leadId) };
            if (s.id === toStageId) return { ...s, leads: [...(s.leads ?? []), { ...lead, stage_id: toStageId }] };
            return s;
        });
        setStages(next);

        const ids = (next.find((s) => s.id === toStageId)?.leads ?? []).map((l) => l.id);
        router.patch(route('leads.move', leadId), { stage_id: toStageId, ids }, { preserveScroll: true, preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pipeline" />
            <div className="flex h-full flex-1 flex-col gap-5 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-display text-3xl tracking-tight">Pipeline de pacientes</h1>
                        <p className="text-muted-foreground">
                            <AnimatedNumber value={totalLeads} /> leads en el embudo · arrástralos entre etapas
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={route('leads.create')}>
                            <Plus className="h-4 w-4" />
                            Nuevo lead
                        </Link>
                    </Button>
                </div>

                <DndContext sensors={sensors} collisionDetection={closestCorners} onDragStart={onDragStart} onDragEnd={onDragEnd}>
                    <div className="flex flex-1 gap-4 overflow-x-auto pb-4">
                        {stages.map((stage) => (
                            <Column key={stage.id} stage={stage} />
                        ))}
                    </div>

                    <DragOverlay dropAnimation={{ duration: 220, easing: 'cubic-bezier(0.16,1,0.3,1)' }}>
                        {activeLead ? (
                            <div className="w-72">
                                <LeadCard lead={activeLead} dragging />
                            </div>
                        ) : null}
                    </DragOverlay>
                </DndContext>
            </div>
        </AppLayout>
    );
}
