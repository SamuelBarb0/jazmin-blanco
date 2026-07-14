import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { channelMeta, colorOf } from '@/lib/crm';
import { cn } from '@/lib/utils';
import { type Lead, type LeadChannel, type Stage, type Tag } from '@/types';
import { router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

interface LeadFormData {
    name: string;
    phone: string;
    email: string;
    channel: LeadChannel;
    source: string;
    service_interest: string;
    value: string;
    stage_id: string;
    notes: string;
    tags: number[];
    [key: string]: string | number | number[];
}

const channelKeys = Object.keys(channelMeta) as LeadChannel[];
const fieldClass =
    'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2';

export default function LeadForm({
    lead,
    stages,
    tags,
    services,
}: {
    lead?: Lead;
    stages: Stage[];
    tags: Tag[];
    services: string[];
}) {
    const isEdit = Boolean(lead);

    const { data, setData, post, put, processing, errors } = useForm<LeadFormData>({
        name: lead?.name ?? '',
        phone: lead?.phone ?? '',
        email: lead?.email ?? '',
        channel: lead?.channel ?? 'whatsapp',
        source: lead?.source ?? '',
        service_interest: lead?.service_interest ?? '',
        value: lead?.value ?? '',
        stage_id: lead?.stage_id ? String(lead.stage_id) : stages[0] ? String(stages[0].id) : '',
        notes: lead?.notes ?? '',
        tags: lead?.tags?.map((t) => t.id) ?? [],
    });

    const toggleTag = (id: number) => {
        setData('tags', data.tags.includes(id) ? data.tags.filter((t) => t !== id) : [...data.tags, id]);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (isEdit && lead) put(route('leads.update', lead.id));
        else post(route('leads.store'));
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-6">
            <div className="grid gap-6 md:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="name">Nombre del paciente *</Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Ej. Valentina Ríos" autoFocus />
                    <InputError message={errors.name} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="phone">Teléfono / WhatsApp</Label>
                    <Input id="phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} placeholder="3001234567" />
                    <InputError message={errors.phone} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="email">Correo</Label>
                    <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} placeholder="opcional@correo.com" />
                    <InputError message={errors.email} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="channel">Canal de origen</Label>
                    <select id="channel" value={data.channel} onChange={(e) => setData('channel', e.target.value as LeadChannel)} className={fieldClass}>
                        {channelKeys.map((k) => (
                            <option key={k} value={k}>
                                {channelMeta[k].label}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.channel} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="service_interest">Servicio de interés</Label>
                    <Input
                        id="service_interest"
                        list="services-list"
                        value={data.service_interest}
                        onChange={(e) => setData('service_interest', e.target.value)}
                        placeholder="Ej. FUE Capilar, Endolifting…"
                    />
                    <datalist id="services-list">
                        {services.map((s) => (
                            <option key={s} value={s} />
                        ))}
                    </datalist>
                    <InputError message={errors.service_interest} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="value">Valor estimado (COP)</Label>
                    <Input id="value" type="number" step="1000" min="0" value={data.value} onChange={(e) => setData('value', e.target.value)} placeholder="0" />
                    <InputError message={errors.value} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="stage_id">Etapa del pipeline</Label>
                <select id="stage_id" value={data.stage_id} onChange={(e) => setData('stage_id', e.target.value)} className={cn(fieldClass, 'max-w-xs')}>
                    {stages.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.name}
                        </option>
                    ))}
                </select>
                <InputError message={errors.stage_id} />
            </div>

            <div className="grid gap-2">
                <Label>Etiquetas</Label>
                <div className="flex flex-wrap gap-2">
                    {tags.map((t) => {
                        const c = colorOf(t.color);
                        const on = data.tags.includes(t.id);
                        return (
                            <button
                                type="button"
                                key={t.id}
                                onClick={() => toggleTag(t.id)}
                                className={cn(
                                    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm transition',
                                    on ? cn(c.soft, c.text, c.border) : 'border-border/60 text-muted-foreground hover:bg-muted/50',
                                )}
                            >
                                <span className={cn('size-2 rounded-full', c.dot)} />
                                {t.name}
                            </button>
                        );
                    })}
                    {tags.length === 0 && <span className="text-muted-foreground text-sm">No hay etiquetas aún.</span>}
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="notes">Notas</Label>
                <Textarea id="notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={3} placeholder="Contexto de la conversación, preferencias, etc." />
                <InputError message={errors.notes} />
            </div>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {isEdit ? 'Guardar cambios' : 'Crear lead'}
                </Button>
                <Button type="button" variant="ghost" onClick={() => router.visit(route('leads.index'))}>
                    Cancelar
                </Button>
            </div>
        </form>
    );
}
