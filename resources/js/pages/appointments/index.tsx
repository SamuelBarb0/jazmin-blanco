import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type Appointment, type AppointmentStatus, type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarDays,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock,
    FileText,
    Mail,
    MessageCircle,
    Phone,
    Sparkles,
    User,
    LoaderCircle,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { FormEventHandler, type ReactNode, useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Agenda', href: '/appointments' }];

type ServiceOpt = { id: number; name: string; duration_minutes: number | null };
type LeadOpt = { id: number; name: string; phone: string | null; email: string | null };

interface PageProps {
    appointments: Appointment[];
    services: ServiceOpt[];
    leads: LeadOpt[];
    statuses: AppointmentStatus[];
    googleConfigured: boolean;
    serviceAccountEmail: string | null;
}

const statusMeta: Record<AppointmentStatus, { label: string; className: string; dot: string }> = {
    scheduled: { label: 'Agendada', className: 'bg-sky-500/15 text-sky-600 dark:text-sky-400', dot: 'bg-sky-500' },
    confirmed: { label: 'Confirmada', className: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400', dot: 'bg-emerald-500' },
    completed: { label: 'Completada', className: 'bg-violet-500/15 text-violet-600 dark:text-violet-400', dot: 'bg-violet-500' },
    cancelled: { label: 'Cancelada', className: 'bg-rose-500/15 text-rose-600 dark:text-rose-400', dot: 'bg-rose-500' },
    no_show: { label: 'No asistió', className: 'bg-amber-500/15 text-amber-600 dark:text-amber-400', dot: 'bg-amber-500' },
};

const monthFmt = new Intl.DateTimeFormat('es-CO', { month: 'long', year: 'numeric' });
const dayLongFmt = new Intl.DateTimeFormat('es-CO', { weekday: 'long', day: 'numeric', month: 'long' });
const timeFmt = new Intl.DateTimeFormat('es-CO', { hour: 'numeric', minute: '2-digit', hour12: true });

const WEEKDAYS = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];

// Clave AAAA-MM-DD en hora local (sin pasar por UTC, para no correr el día).
function ymd(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

type FormShape = {
    lead_id: string;
    service_id: string;
    patient_name: string;
    patient_phone: string;
    patient_email: string;
    starts_at: string;
    duration_minutes: string;
    status: AppointmentStatus;
    notes: string;
};

const emptyForm: FormShape = {
    lead_id: '',
    service_id: '',
    patient_name: '',
    patient_phone: '',
    patient_email: '',
    starts_at: '',
    duration_minutes: '',
    status: 'scheduled',
    notes: '',
};

export default function AppointmentsIndex({ appointments, services, leads, statuses, googleConfigured }: PageProps) {
    const { flash } = usePage<SharedData>().props;
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Appointment | null>(null);
    const [detail, setDetail] = useState<Appointment | null>(null);

    const today = useMemo(() => new Date(), []);
    const todayKey = ymd(today);
    // Mes que se está viendo (primer día del mes) y día seleccionado en el panel.
    const [cursor, setCursor] = useState(() => new Date(today.getFullYear(), today.getMonth(), 1));
    const [selectedKey, setSelectedKey] = useState(todayKey);

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm<FormShape>({ ...emptyForm });

    const openNew = (dayKey?: string) => {
        reset();
        clearErrors();
        setEditing(null);
        if (dayKey) setData('starts_at', `${dayKey}T09:00`);
        setOpen(true);
    };

    const openEdit = (a: Appointment) => {
        clearErrors();
        setEditing(a);
        const durationMin = Math.round((new Date(a.ends_at).getTime() - new Date(a.starts_at).getTime()) / 60000);
        setData({
            lead_id: a.lead_id ? String(a.lead_id) : '',
            service_id: a.service_id ? String(a.service_id) : '',
            patient_name: a.patient_name,
            patient_phone: a.patient_phone ?? '',
            patient_email: a.patient_email ?? '',
            starts_at: a.starts_at.slice(0, 16),
            duration_minutes: durationMin > 0 ? String(durationMin) : '',
            status: a.status,
            notes: a.notes ?? '',
        });
        setOpen(true);
    };

    const onLeadChange = (value: string) => {
        setData('lead_id', value);
        const lead = leads.find((l) => String(l.id) === value);
        if (lead) {
            setData((prev) => ({
                ...prev,
                lead_id: value,
                patient_name: lead.name,
                patient_phone: lead.phone ?? prev.patient_phone,
                patient_email: lead.email ?? prev.patient_email,
            }));
        }
    };

    const onServiceChange = (value: string) => {
        const service = services.find((s) => String(s.id) === value);
        setData((prev) => ({
            ...prev,
            service_id: value,
            duration_minutes: !prev.duration_minutes && service?.duration_minutes ? String(service.duration_minutes) : prev.duration_minutes,
        }));
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) {
            put(route('appointments.update', editing.id), opts);
        } else {
            post(route('appointments.store'), opts);
        }
    };

    const destroy = (a: Appointment) => {
        if (confirm(`¿Eliminar la cita de "${a.patient_name}"? También se quitará de Google Calendar.`)) {
            router.delete(route('appointments.destroy', a.id), { preserveScroll: true });
        }
    };

    // Mapa día (AAAA-MM-DD) -> citas, ordenadas por hora.
    const byDay = useMemo(() => {
        const map = new Map<string, Appointment[]>();
        for (const a of appointments) {
            const key = a.starts_at.slice(0, 10);
            if (!map.has(key)) map.set(key, []);
            map.get(key)!.push(a);
        }
        for (const list of map.values()) list.sort((x, y) => x.starts_at.localeCompare(y.starts_at));
        return map;
    }, [appointments]);

    // Celdas del calendario: 6 semanas (42 días) empezando en lunes.
    const cells = useMemo(() => {
        const firstOfMonth = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
        const mondayOffset = (firstOfMonth.getDay() + 6) % 7; // lunes = 0
        const start = new Date(firstOfMonth);
        start.setDate(firstOfMonth.getDate() - mondayOffset);
        return Array.from({ length: 42 }, (_, i) => {
            const date = new Date(start);
            date.setDate(start.getDate() + i);
            const key = ymd(date);
            return {
                key,
                date,
                day: date.getDate(),
                inMonth: date.getMonth() === cursor.getMonth(),
                isToday: key === todayKey,
                items: byDay.get(key) ?? [],
            };
        });
    }, [cursor, byDay, todayKey]);

    const selectedItems = byDay.get(selectedKey) ?? [];

    const goMonth = (delta: number) => setCursor((c) => new Date(c.getFullYear(), c.getMonth() + delta, 1));
    const goToday = () => {
        setCursor(new Date(today.getFullYear(), today.getMonth(), 1));
        setSelectedKey(todayKey);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Agenda" />
            <div className="flex h-full flex-1 flex-col gap-5 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-display text-3xl tracking-tight">Agenda</h1>
                        <p className="text-muted-foreground">{appointments.length} citas · sincronizadas con Google Calendar.</p>
                    </div>
                    <Button onClick={() => openNew(selectedKey)}>
                        <Plus className="h-4 w-4" /> Nueva cita
                    </Button>
                </div>

                {!googleConfigured && (
                    <div className="flex items-center gap-3 rounded-xl border border-amber-300/60 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                        <AlertTriangle className="size-5 shrink-0" />
                        <div>
                            Google Calendar no está conectado. Las citas se guardarán pero no se sincronizarán.{' '}
                            <Link href="/settings/calendar" className="font-medium underline">
                                Conectar ahora
                            </Link>
                            .
                        </div>
                    </div>
                )}

                {flash?.success && (
                    <div className="rounded-lg border border-primary/30 bg-primary/10 px-4 py-3 text-sm font-medium text-primary">{flash.success}</div>
                )}

                <div className="grid flex-1 gap-4 lg:grid-cols-[1fr_340px]">
                    {/* Calendario mensual */}
                    <div className="glass flex flex-col rounded-2xl p-4">
                        <div className="mb-3 flex items-center justify-between gap-2">
                            <h2 className="font-display text-xl capitalize">{monthFmt.format(cursor)}</h2>
                            <div className="flex items-center gap-1">
                                <Button variant="outline" size="sm" onClick={goToday}>
                                    Hoy
                                </Button>
                                <Button variant="ghost" size="icon" className="size-8" onClick={() => goMonth(-1)} aria-label="Mes anterior">
                                    <ChevronLeft className="size-4" />
                                </Button>
                                <Button variant="ghost" size="icon" className="size-8" onClick={() => goMonth(1)} aria-label="Mes siguiente">
                                    <ChevronRight className="size-4" />
                                </Button>
                            </div>
                        </div>

                        <div className="flex flex-1 flex-col gap-1">
                            <div className="grid grid-cols-7 gap-1 text-center">
                                {WEEKDAYS.map((w) => (
                                    <div key={w} className="text-muted-foreground pb-1 text-xs font-medium capitalize">
                                        {w}
                                    </div>
                                ))}
                            </div>
                            <div className="grid flex-1 auto-rows-fr grid-cols-7 grid-rows-6 gap-1">
                                {cells.map((cell) => {
                                    const isSelected = cell.key === selectedKey;
                                    return (
                                        <button
                                            key={cell.key}
                                            type="button"
                                            onClick={() => setSelectedKey(cell.key)}
                                            className={cn(
                                                'flex h-full min-h-[44px] flex-col gap-0.5 rounded-lg border p-1 text-left transition-colors sm:min-h-[80px] sm:gap-1 sm:p-2',
                                                cell.inMonth ? 'bg-background/40' : 'bg-transparent opacity-40',
                                                isSelected ? 'border-primary ring-primary/40 ring-1' : 'border-border/40 hover:border-border',
                                            )}
                                        >
                                            <span
                                                className={cn(
                                                    'flex size-6 items-center justify-center self-end rounded-full text-xs sm:size-7 sm:text-sm',
                                                    cell.isToday ? 'bg-primary text-primary-foreground font-semibold' : 'text-muted-foreground',
                                                )}
                                            >
                                                {cell.day}
                                            </span>

                                            {/* Móvil: solo puntos de color (el panel de abajo muestra el detalle) */}
                                            {cell.items.length > 0 && (
                                                <div className="flex flex-wrap items-center gap-0.5 px-0.5 sm:hidden">
                                                    {cell.items.slice(0, 4).map((a) => (
                                                        <span key={a.id} className={cn('size-1.5 rounded-full', statusMeta[a.status].dot)} />
                                                    ))}
                                                    {cell.items.length > 4 && <span className="text-muted-foreground text-[9px] leading-none">+{cell.items.length - 4}</span>}
                                                </div>
                                            )}

                                            {/* Desktop: pastillas con nombre */}
                                            <div className="hidden flex-col gap-0.5 overflow-hidden sm:flex">
                                                {cell.items.slice(0, 4).map((a) => (
                                                    <span
                                                        key={a.id}
                                                        className={cn(
                                                            'flex items-center gap-1 truncate rounded px-1 py-0.5 text-[11px] leading-tight',
                                                            statusMeta[a.status].className,
                                                        )}
                                                        title={`${timeFmt.format(new Date(a.starts_at))} · ${a.patient_name}`}
                                                    >
                                                        <span className={cn('size-1.5 shrink-0 rounded-full', statusMeta[a.status].dot)} />
                                                        <span className="truncate">{a.patient_name}</span>
                                                    </span>
                                                ))}
                                                {cell.items.length > 4 && (
                                                    <span className="text-muted-foreground px-1 text-[11px]">+{cell.items.length - 4} más</span>
                                                )}
                                            </div>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    </div>

                    {/* Panel del día seleccionado */}
                    <div className="glass flex flex-col gap-3 rounded-2xl p-4">
                        <div className="flex items-start justify-between gap-2">
                            <h2 className="text-sm font-medium capitalize leading-tight">
                                {dayLongFmt.format(new Date(selectedKey + 'T00:00:00'))}
                            </h2>
                            <Button variant="outline" size="sm" onClick={() => openNew(selectedKey)}>
                                <Plus className="h-4 w-4" /> Cita
                            </Button>
                        </div>

                        <AnimatePresence mode="wait">
                            <motion.div
                                key={selectedKey}
                                initial={{ opacity: 0, y: 6 }}
                                animate={{ opacity: 1, y: 0 }}
                                exit={{ opacity: 0 }}
                                transition={{ duration: 0.2 }}
                                className="flex flex-col gap-2"
                            >
                                {selectedItems.length === 0 ? (
                                    <div className="text-muted-foreground flex flex-col items-center gap-2 py-10 text-center text-sm">
                                        <CalendarDays className="size-8 opacity-40" />
                                        <p>Sin citas este día.</p>
                                    </div>
                                ) : (
                                    selectedItems.map((a) => {
                                        const st = statusMeta[a.status];
                                        return (
                                            <div
                                                key={a.id}
                                                role="button"
                                                tabIndex={0}
                                                onClick={() => setDetail(a)}
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Enter' || e.key === ' ') {
                                                        e.preventDefault();
                                                        setDetail(a);
                                                    }
                                                }}
                                                className="bg-background/40 hover:bg-muted/40 group flex cursor-pointer items-center gap-3 rounded-xl border border-border/40 px-3 py-2.5 text-left transition-colors"
                                            >
                                                <div className="flex w-14 shrink-0 flex-col items-center">
                                                    <span className="font-display text-base leading-tight">{timeFmt.format(new Date(a.starts_at))}</span>
                                                    <span className="text-muted-foreground flex items-center gap-0.5 text-[10px]">
                                                        <Clock className="size-2.5" />
                                                        {timeFmt.format(new Date(a.ends_at))}
                                                    </span>
                                                </div>
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm font-medium">{a.patient_name}</p>
                                                    <p className="text-muted-foreground truncate text-xs">
                                                        {a.service?.name ?? 'Sin servicio'}
                                                        {a.patient_phone ? ` · ${a.patient_phone}` : ''}
                                                    </p>
                                                    <span className={cn('mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium', st.className)}>
                                                        {st.label}
                                                        {googleConfigured &&
                                                            (a.google_sync_error ? (
                                                                <AlertTriangle className="size-3 text-amber-500" />
                                                            ) : a.google_event_id ? (
                                                                <CheckCircle2 className="size-3 text-emerald-500" />
                                                            ) : null)}
                                                    </span>
                                                </div>
                                                <div className="flex shrink-0 flex-col gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-7"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            openEdit(a);
                                                        }}
                                                    >
                                                        <Pencil className="size-3.5" />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="text-destructive hover:text-destructive size-7"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            destroy(a);
                                                        }}
                                                    >
                                                        <Trash2 className="size-3.5" />
                                                    </Button>
                                                </div>
                                            </div>
                                        );
                                    })
                                )}
                            </motion.div>
                        </AnimatePresence>
                    </div>
                </div>
            </div>

            {/* Diálogo de cita */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle className="font-display text-xl">{editing ? 'Editar cita' : 'Nueva cita'}</DialogTitle>
                        <DialogDescription>Se sincroniza con Google Calendar al guardar.</DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="lead_id">Paciente del CRM (opcional)</Label>
                            <select
                                id="lead_id"
                                value={data.lead_id}
                                onChange={(e) => onLeadChange(e.target.value)}
                                className="border-input bg-background flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                            >
                                <option value="">— Sin vincular —</option>
                                {leads.map((l) => (
                                    <option key={l.id} value={l.id}>
                                        {l.name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="patient_name">Nombre del paciente</Label>
                            <Input id="patient_name" value={data.patient_name} onChange={(e) => setData('patient_name', e.target.value)} required />
                            <FieldError message={errors.patient_name} />
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="patient_phone">Teléfono</Label>
                                <Input id="patient_phone" value={data.patient_phone} onChange={(e) => setData('patient_phone', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="patient_email">Correo</Label>
                                <Input id="patient_email" type="email" value={data.patient_email} onChange={(e) => setData('patient_email', e.target.value)} />
                                <FieldError message={errors.patient_email} />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="service_id">Servicio</Label>
                            <select
                                id="service_id"
                                value={data.service_id}
                                onChange={(e) => onServiceChange(e.target.value)}
                                className="border-input bg-background flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                            >
                                <option value="">— Sin servicio —</option>
                                {services.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                        {s.duration_minutes ? ` (${s.duration_minutes} min)` : ''}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="starts_at">Fecha y hora</Label>
                                <Input id="starts_at" type="datetime-local" value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} required />
                                <FieldError message={errors.starts_at} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="duration_minutes">Duración (min)</Label>
                                <Input
                                    id="duration_minutes"
                                    type="number"
                                    min={5}
                                    step={5}
                                    value={data.duration_minutes}
                                    onChange={(e) => setData('duration_minutes', e.target.value)}
                                    placeholder="45"
                                />
                                <FieldError message={errors.duration_minutes} />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="status">Estado</Label>
                            <select
                                id="status"
                                value={data.status}
                                onChange={(e) => setData('status', e.target.value as AppointmentStatus)}
                                className="border-input bg-background flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                            >
                                {statuses.map((s) => (
                                    <option key={s} value={s}>
                                        {statusMeta[s].label}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="notes">Notas</Label>
                            <Textarea id="notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                {editing ? 'Guardar cambios' : 'Agendar cita'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Diálogo de detalle de la cita */}
            <Dialog open={detail !== null} onOpenChange={(o) => !o && setDetail(null)}>
                <DialogContent className="max-h-[90vh] overflow-y-auto">
                    {detail && (
                        <>
                            <DialogHeader>
                                <div className="flex items-center justify-between gap-3 pr-6">
                                    <DialogTitle className="font-display text-xl">{detail.patient_name}</DialogTitle>
                                    <span className={cn('rounded-full px-2.5 py-0.5 text-xs font-medium', statusMeta[detail.status].className)}>
                                        {statusMeta[detail.status].label}
                                    </span>
                                </div>
                                <DialogDescription className="capitalize">
                                    {dayLongFmt.format(new Date(detail.starts_at.slice(0, 10) + 'T00:00:00'))}
                                </DialogDescription>
                            </DialogHeader>

                            <div className="space-y-1">
                                <InfoRow icon={Clock} label="Horario">
                                    {timeFmt.format(new Date(detail.starts_at))} – {timeFmt.format(new Date(detail.ends_at))}
                                </InfoRow>
                                <InfoRow icon={Sparkles} label="Servicio">{detail.service?.name ?? 'Sin servicio'}</InfoRow>
                                {detail.patient_phone && (
                                    <InfoRow icon={Phone} label="Teléfono">
                                        <span className="flex items-center gap-2">
                                            {detail.patient_phone}
                                            <a
                                                href={`https://wa.me/${detail.patient_phone.replace(/\D/g, '')}`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="text-primary inline-flex items-center gap-1 text-xs font-medium hover:underline"
                                            >
                                                <MessageCircle className="size-3.5" /> WhatsApp
                                            </a>
                                        </span>
                                    </InfoRow>
                                )}
                                {detail.patient_email && (
                                    <InfoRow icon={Mail} label="Correo">
                                        <a href={`mailto:${detail.patient_email}`} className="text-primary hover:underline">
                                            {detail.patient_email}
                                        </a>
                                    </InfoRow>
                                )}
                                {detail.lead && <InfoRow icon={User} label="Paciente del CRM">{detail.lead.name}</InfoRow>}
                                {detail.notes && <InfoRow icon={FileText} label="Notas">{detail.notes}</InfoRow>}
                                <InfoRow icon={CalendarDays} label="Google Calendar">
                                    {detail.google_sync_error ? (
                                        <span className="flex items-center gap-1.5 text-amber-600 dark:text-amber-400">
                                            <AlertTriangle className="size-4" /> Error al sincronizar
                                        </span>
                                    ) : detail.google_event_id ? (
                                        <span className="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
                                            <CheckCircle2 className="size-4" /> Sincronizada
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground">No sincronizada</span>
                                    )}
                                </InfoRow>
                            </div>

                            {detail.google_sync_error && (
                                <p className="rounded-lg border border-amber-300/60 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                                    {detail.google_sync_error}
                                </p>
                            )}

                            <DialogFooter>
                                <Button
                                    variant="ghost"
                                    className="text-destructive hover:text-destructive"
                                    onClick={() => {
                                        const a = detail;
                                        setDetail(null);
                                        destroy(a);
                                    }}
                                >
                                    <Trash2 className="h-4 w-4" /> Eliminar
                                </Button>
                                <Button
                                    onClick={() => {
                                        const a = detail;
                                        setDetail(null);
                                        openEdit(a);
                                    }}
                                >
                                    <Pencil className="h-4 w-4" /> Editar
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function InfoRow({ icon: Icon, label, children }: { icon: typeof Clock; label: string; children: ReactNode }) {
    return (
        <div className="flex items-start gap-3 border-b border-border/30 py-2 last:border-0">
            <Icon className="text-muted-foreground mt-0.5 size-4 shrink-0" />
            <div className="min-w-0 flex-1">
                <p className="text-muted-foreground text-xs">{label}</p>
                <div className="text-sm">{children}</div>
            </div>
        </div>
    );
}

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="text-destructive text-xs">{message}</p>;
}
