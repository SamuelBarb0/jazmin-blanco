import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type Service, type SharedData } from '@/types';
import { router, useForm } from '@inertiajs/react';
import { LoaderCircle, Sparkles } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface ServiceFormData {
    name: string;
    category: string;
    short_description: string;
    description: string;
    ai_context: string;
    price: string;
    duration_minutes: string;
    is_active: boolean;
    [key: string]: string | boolean;
}

export default function ServiceForm({ service, aiConfigured }: { service?: Service; aiConfigured: boolean }) {
    const isEdit = Boolean(service);

    const { data, setData, post, put, processing, errors } = useForm<ServiceFormData>({
        name: service?.name ?? '',
        category: service?.category ?? '',
        short_description: service?.short_description ?? '',
        description: service?.description ?? '',
        ai_context: service?.ai_context ?? '',
        price: service?.price ?? '',
        duration_minutes: service?.duration_minutes ? String(service.duration_minutes) : '',
        is_active: service?.is_active ?? true,
    });

    const [generating, setGenerating] = useState(false);
    const [aiError, setAiError] = useState<string | null>(null);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (isEdit && service) {
            put(route('services.update', service.id));
        } else {
            post(route('services.store'));
        }
    };

    const generateContext = () => {
        setAiError(null);
        if (!data.name.trim()) {
            setAiError('Escribe primero el nombre del servicio.');
            return;
        }

        setGenerating(true);
        router.post(
            route('services.generate-context'),
            {
                name: data.name,
                category: data.category,
                short_description: data.short_description,
                price: data.price,
                duration_minutes: data.duration_minutes,
                notes: data.description,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    const generated = (page.props as unknown as SharedData).flash?.generatedContext;
                    if (generated) {
                        setData('ai_context', generated);
                    }
                },
                onError: (errs) => {
                    setAiError(errs.ai ?? 'No se pudo generar el contexto.');
                },
                onFinish: () => setGenerating(false),
            },
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-6">
            <div className="grid gap-6 md:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="name">Nombre del servicio *</Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Ej. Limpieza facial profunda" autoFocus />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="category">Categoría</Label>
                    <Input id="category" value={data.category} onChange={(e) => setData('category', e.target.value)} placeholder="Ej. Facial, Corporal, Láser" />
                    <InputError message={errors.category} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="price">Precio (referencial)</Label>
                    <Input id="price" type="number" step="0.01" min="0" value={data.price} onChange={(e) => setData('price', e.target.value)} placeholder="0.00" />
                    <InputError message={errors.price} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="duration_minutes">Duración (minutos)</Label>
                    <Input id="duration_minutes" type="number" min="0" value={data.duration_minutes} onChange={(e) => setData('duration_minutes', e.target.value)} placeholder="Ej. 45" />
                    <InputError message={errors.duration_minutes} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="short_description">Descripción corta</Label>
                <Input id="short_description" value={data.short_description} onChange={(e) => setData('short_description', e.target.value)} placeholder="Una línea que resuma el servicio" />
                <InputError message={errors.short_description} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="description">Notas internas</Label>
                <Textarea id="description" value={data.description} onChange={(e) => setData('description', e.target.value)} placeholder="Detalles, técnicas, insumos… (también sirven de guía para la IA)" rows={3} />
                <InputError message={errors.description} />
            </div>

            {/* Contexto generado con IA */}
            <div className="rounded-xl border border-border bg-accent/40 p-4">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <Label htmlFor="ai_context" className="text-base">
                            Contexto del servicio
                        </Label>
                        <p className="text-muted-foreground text-sm">Texto de presentación para tus pacientes, potenciado con IA.</p>
                    </div>
                    <Button type="button" variant="secondary" onClick={generateContext} disabled={generating || !aiConfigured}>
                        {generating ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Sparkles className="h-4 w-4" />}
                        Generar con IA
                    </Button>
                </div>
                {!aiConfigured && (
                    <p className="mb-2 text-sm text-amber-600 dark:text-amber-500">
                        La generación con IA está deshabilitada. Actívala en{' '}
                        <a href={route('ai.edit')} className="font-medium underline">
                            Configuración → Integración IA
                        </a>
                        .
                    </p>
                )}
                <Textarea
                    id="ai_context"
                    value={data.ai_context}
                    onChange={(e) => setData('ai_context', e.target.value)}
                    placeholder="Aquí aparecerá el contexto generado. También puedes escribirlo o editarlo manualmente."
                    rows={8}
                />
                <InputError message={aiError ?? errors.ai_context} />
            </div>

            <div className="flex items-center gap-3">
                <Checkbox id="is_active" checked={data.is_active} onCheckedChange={(checked) => setData('is_active', checked === true)} />
                <Label htmlFor="is_active">Servicio activo (visible)</Label>
            </div>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {isEdit ? 'Guardar cambios' : 'Crear servicio'}
                </Button>
                <Button type="button" variant="ghost" onClick={() => router.visit(route('services.index'))}>
                    Cancelar
                </Button>
            </div>
        </form>
    );
}
