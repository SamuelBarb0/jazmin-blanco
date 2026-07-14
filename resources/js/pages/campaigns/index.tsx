import CampaignMediaManager from '@/components/campaign-media-manager';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type Campaign, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Megaphone, Pencil, Plus, RefreshCw, Sparkles, Trash2 } from 'lucide-react';
import { motion } from 'motion/react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Campañas', href: '/campaigns' }];

type ServiceOpt = { id: number; name: string };
type Platform = 'meta' | 'facebook' | 'instagram';

const platformLabels: Record<Platform, string> = {
    meta: 'Meta (Facebook + Instagram)',
    facebook: 'Facebook',
    instagram: 'Instagram',
};

// Estado real que trae Meta (effective_status). Etiqueta + color por estado
// conocido; los desconocidos caen al valor crudo con estilo neutro.
const metaStatusMeta: Record<string, { label: string; className: string }> = {
    ACTIVE: { label: 'Activa', className: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400' },
    PAUSED: { label: 'Pausada', className: 'bg-amber-500/15 text-amber-600 dark:text-amber-400' },
    CAMPAIGN_PAUSED: { label: 'Pausada', className: 'bg-amber-500/15 text-amber-600 dark:text-amber-400' },
    ADSET_PAUSED: { label: 'Conjunto pausado', className: 'bg-amber-500/15 text-amber-600 dark:text-amber-400' },
    IN_PROCESS: { label: 'En proceso', className: 'bg-sky-500/15 text-sky-600 dark:text-sky-400' },
    PENDING_REVIEW: { label: 'En revisión', className: 'bg-sky-500/15 text-sky-600 dark:text-sky-400' },
    WITH_ISSUES: { label: 'Con problemas', className: 'bg-red-500/15 text-red-600 dark:text-red-400' },
    DISAPPROVED: { label: 'Rechazada', className: 'bg-red-500/15 text-red-600 dark:text-red-400' },
    ARCHIVED: { label: 'Archivada', className: 'bg-muted text-muted-foreground' },
    DELETED: { label: 'Eliminada', className: 'bg-muted text-muted-foreground' },
};

const statusDisplay = (status: string): { label: string; className: string } =>
    metaStatusMeta[status] ?? { label: status, className: 'bg-muted text-muted-foreground' };

// Orden fijo de los chips: primero Activas, luego Pausadas, después el resto.
// (El chip "Todas" se pinta al final, aparte.)
const STATUS_ORDER = ['ACTIVE', 'CAMPAIGN_ACTIVE', 'PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED', 'IN_PROCESS', 'PENDING_REVIEW', 'WITH_ISSUES', 'DISAPPROVED', 'ARCHIVED', 'DELETED'];
const statusRank = (status: string): number => {
    const i = STATUS_ORDER.indexOf(status);
    return i === -1 ? STATUS_ORDER.length : i;
};

type FormShape = {
    name: string;
    service_id: string;
    platform: Platform;
    meta_campaign_id: string;
    offer: string;
    is_active: boolean;
};

const emptyForm: FormShape = {
    name: '',
    service_id: '',
    platform: 'meta',
    meta_campaign_id: '',
    offer: '',
    is_active: true,
};

export default function CampaignsIndex({
    campaigns,
    services,
    metaAdsConfigured,
    filters,
    statusCounts,
    total,
}: {
    campaigns: Campaign[];
    services: ServiceOpt[];
    metaAdsConfigured: boolean;
    filters: { status: string | null };
    statusCounts: Record<string, number>;
    total: number;
}) {
    const { flash } = usePage<SharedData>().props;
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Campaign | null>(null);
    const [importing, setImporting] = useState(false);

    const activeStatus = filters.status;
    // Activas → Pausadas → resto → (Todas se pinta al final).
    const statusEntries = Object.entries(statusCounts).sort(([a], [b]) => statusRank(a) - statusRank(b));

    const filterByStatus = (status: string | null) => {
        router.get(
            route('campaigns.index'),
            status ? { status } : {},
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    const importFromMeta = () => {
        setImporting(true);
        router.post(
            route('campaigns.import'),
            {},
            { preserveScroll: true, onFinish: () => setImporting(false) },
        );
    };

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm<FormShape>({ ...emptyForm });

    const openNew = () => {
        reset();
        clearErrors();
        setEditing(null);
        setOpen(true);
    };

    const openEdit = (c: Campaign) => {
        clearErrors();
        setEditing(c);
        setData({
            name: c.name,
            service_id: c.service_id ? String(c.service_id) : '',
            platform: c.platform,
            meta_campaign_id: c.meta_campaign_id ?? '',
            offer: c.offer ?? '',
            is_active: c.is_active,
        });
        setOpen(true);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) {
            put(route('campaigns.update', editing.id), opts);
        } else {
            post(route('campaigns.store'), opts);
        }
    };

    const destroy = (c: Campaign) => {
        if (confirm(`¿Eliminar la campaña "${c.name}"?`)) {
            router.delete(route('campaigns.destroy', c.id), { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Campañas" />
            <div className="flex h-full flex-1 flex-col gap-5 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-display text-3xl tracking-tight">Campañas de Meta</h1>
                        <p className="text-muted-foreground">
                            Define el contexto de cada anuncio para que el bot responda enfocado en su oferta. Los anuncios Click-to-WhatsApp se
                            registran solos cuando llega el primer paciente.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        {metaAdsConfigured && (
                            <Button variant="outline" onClick={importFromMeta} disabled={importing}>
                                <RefreshCw className={cn('h-4 w-4', importing && 'animate-spin')} />
                                {importing ? 'Importando…' : 'Importar de Meta'}
                            </Button>
                        )}
                        <Button onClick={openNew}>
                            <Plus className="h-4 w-4" /> Nueva campaña
                        </Button>
                    </div>
                </div>

                {flash?.success && (
                    <div className="rounded-lg border border-primary/30 bg-primary/10 px-4 py-3 text-sm font-medium text-primary">{flash.success}</div>
                )}

                {flash?.error && (
                    <div className="border-destructive/30 bg-destructive/10 text-destructive rounded-lg border px-4 py-3 text-sm font-medium">
                        {flash.error}
                    </div>
                )}

                {statusEntries.length > 0 && (
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-muted-foreground text-xs font-medium">Estado en Meta:</span>
                        {statusEntries.map(([status, count]) => (
                            <FilterChip
                                key={status}
                                label={statusDisplay(status).label}
                                count={count}
                                active={activeStatus === status}
                                onClick={() => filterByStatus(status)}
                            />
                        ))}
                        <FilterChip label="Todas" count={total} active={!activeStatus} onClick={() => filterByStatus(null)} />
                    </div>
                )}

                {campaigns.length === 0 ? (
                    <div className="glass text-muted-foreground flex flex-col items-center gap-3 rounded-2xl px-6 py-16 text-center">
                        <Megaphone className="size-10 opacity-40" />
                        <p>Aún no hay campañas. Crea la primera para darle contexto al bot.</p>
                        <Button variant="outline" onClick={openNew}>
                            <Plus className="h-4 w-4" /> Crear campaña
                        </Button>
                    </div>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {campaigns.map((c, i) => (
                            <motion.div
                                key={c.id}
                                initial={{ opacity: 0, y: 8 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ duration: 0.25, delay: Math.min(i * 0.03, 0.3) }}
                                className="glass flex flex-col gap-3 rounded-2xl p-4"
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div className="flex items-center gap-2">
                                        <span className="bg-primary/10 text-primary flex size-9 items-center justify-center rounded-xl">
                                            <Megaphone className="size-4" />
                                        </span>
                                        <div>
                                            <p className="font-medium leading-tight">{c.name}</p>
                                            <p className="text-muted-foreground text-xs">{platformLabels[c.platform]}</p>
                                        </div>
                                    </div>
                                    {c.meta_status ? (
                                        <span className={cn('rounded-full px-2 py-0.5 text-[10px] font-medium', statusDisplay(c.meta_status).className)}>
                                            {statusDisplay(c.meta_status).label}
                                        </span>
                                    ) : (
                                        <span
                                            className={cn(
                                                'rounded-full px-2 py-0.5 text-[10px] font-medium',
                                                c.is_active ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400' : 'bg-muted text-muted-foreground',
                                            )}
                                        >
                                            {c.is_active ? 'Activa' : 'Inactiva'}
                                        </span>
                                    )}
                                </div>

                                {c.service && (
                                    <p className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <Sparkles className="size-3" /> {c.service.name}
                                    </p>
                                )}

                                {c.offer && <p className="text-muted-foreground line-clamp-3 text-sm">{c.offer}</p>}

                                <div className="mt-auto flex justify-end gap-1 pt-1">
                                    <Button variant="ghost" size="icon" className="size-8" onClick={() => openEdit(c)}>
                                        <Pencil className="size-4" />
                                    </Button>
                                    <Button variant="ghost" size="icon" className="text-destructive hover:text-destructive size-8" onClick={() => destroy(c)}>
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                            </motion.div>
                        ))}
                    </div>
                )}
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle className="font-display text-xl">{editing ? 'Editar campaña' : 'Nueva campaña'}</DialogTitle>
                        <DialogDescription>El bot usará este contexto cuando atienda a un paciente que venga de esta campaña.</DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Nombre de la campaña</Label>
                            <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Ej. Promo Botox Junio" required />
                            <FieldError message={errors.name} />
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="platform">Plataforma</Label>
                                <select
                                    id="platform"
                                    value={data.platform}
                                    onChange={(e) => setData('platform', e.target.value as Platform)}
                                    className="border-input bg-background flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                >
                                    {(Object.keys(platformLabels) as Platform[]).map((p) => (
                                        <option key={p} value={p}>
                                            {platformLabels[p]}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="service_id">Servicio promocionado</Label>
                                <select
                                    id="service_id"
                                    value={data.service_id}
                                    onChange={(e) => setData('service_id', e.target.value)}
                                    className="border-input bg-background flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                >
                                    <option value="">— Ninguno —</option>
                                    {services.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="offer">Oferta / ángulo del anuncio</Label>
                            <Textarea
                                id="offer"
                                value={data.offer}
                                onChange={(e) => setData('offer', e.target.value)}
                                rows={4}
                                placeholder="Ej. 20% de descuento en la primera sesión de Botox durante junio. Enfócate en suavizar líneas de expresión y verse natural."
                            />
                            <p className="text-muted-foreground text-xs">Esto es lo que el bot tendrá presente para responder enfocado en este anuncio.</p>
                            <FieldError message={errors.offer} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="meta_campaign_id">ID del anuncio de Meta (opcional)</Label>
                            <Input
                                id="meta_campaign_id"
                                value={data.meta_campaign_id}
                                onChange={(e) => setData('meta_campaign_id', e.target.value)}
                                placeholder="Ej. 120210000000000000"
                                className="font-mono text-xs"
                            />
                            <p className="text-muted-foreground text-xs">
                                ID del anuncio Click-to-WhatsApp. Si lo pones, los pacientes que lleguen de ese anuncio se asocian a esta campaña.
                                Si lo dejas vacío, la campaña se crea sola cuando llegue el primer paciente.
                            </p>
                        </div>

                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} className="size-4 rounded border-input" />
                            Campaña activa
                        </label>

                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {editing ? 'Guardar cambios' : 'Crear campaña'}
                            </Button>
                        </DialogFooter>
                    </form>

                    {editing ? (
                        <div className="mt-2 border-t border-border/60 pt-4">
                            <CampaignMediaManager
                                campaign={editing}
                                media={campaigns.find((c) => c.id === editing.id)?.media ?? editing.media ?? []}
                            />
                        </div>
                    ) : (
                        <p className="text-muted-foreground mt-2 border-t border-border/60 pt-4 text-xs">
                            Guarda la campaña primero para poder agregarle fotos y videos.
                        </p>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="text-destructive text-xs">{message}</p>;
}

function FilterChip({ label, count, active, onClick }: { label: string; count: number; active: boolean; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                active ? 'border-primary bg-primary/10 text-primary' : 'border-input text-muted-foreground hover:bg-muted',
            )}
        >
            {label}
            <span className={cn('rounded-full px-1.5 text-[10px]', active ? 'bg-primary/20' : 'bg-muted')}>{count}</span>
        </button>
    );
}
