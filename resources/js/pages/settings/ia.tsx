import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, KeyRound, LoaderCircle, Sparkles } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Integración IA', href: '/settings/ia' }];

const modelLabels: Record<string, string> = {
    'claude-opus-4-8': 'Claude Opus 4.8 — el más capaz (recomendado)',
    'claude-sonnet-4-6': 'Claude Sonnet 4.6 — equilibrado y más económico',
    'claude-haiku-4-5': 'Claude Haiku 4.5 — el más rápido y barato',
};

interface BotConfig {
    clinic_name: string;
    clinic_address: string;
    clinic_hours: string;
    clinic_payment: string;
    bot_persona: string;
    [key: string]: string;
}

interface IaProps {
    configured: boolean;
    keyPreview: string | null;
    fromEnv: boolean;
    model: string;
    models: string[];
    bot: BotConfig;
}

export default function IaSettings({ configured, keyPreview, fromEnv, model, models, bot }: IaProps) {
    const { flash } = usePage<SharedData>().props;
    const [testing, setTesting] = useState(false);

    const { data, setData, put, processing, errors } = useForm<{ api_key: string; model: string }>({
        api_key: '',
        model: model,
    });

    const botForm = useForm<BotConfig>({ ...bot });

    const save: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('ai.update'), { preserveScroll: true });
    };

    const test = () => {
        setTesting(true);
        router.post(
            route('ai.test'),
            { api_key: data.api_key, model: data.model },
            { preserveScroll: true, preserveState: true, onFinish: () => setTesting(false) },
        );
    };

    const removeKey = () => {
        if (confirm('¿Eliminar la API key guardada? La IA quedará deshabilitada.')) {
            router.delete(route('ai.destroy'), { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Integración IA" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Integración con IA (Claude)"
                        description="Conecta tu cuenta de Anthropic para que el sistema genere contenido y, próximamente, responda a tus pacientes."
                    />

                    {/* Estado actual */}
                    {configured ? (
                        <div className="flex items-center gap-3 rounded-xl border border-emerald-300/50 bg-emerald-50 px-4 py-3 text-sm dark:border-emerald-500/30 dark:bg-emerald-500/10">
                            <CheckCircle2 className="size-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                            <div className="text-emerald-800 dark:text-emerald-300">
                                <span className="font-medium">IA activa.</span> Clave configurada{' '}
                                {keyPreview && <code className="rounded bg-emerald-500/15 px-1">{keyPreview}</code>}
                                {fromEnv && <span className="text-emerald-700/80 dark:text-emerald-400/80"> (desde el archivo .env)</span>}.
                            </div>
                        </div>
                    ) : (
                        <div className="flex items-center gap-3 rounded-xl border border-amber-300/60 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                            <Sparkles className="size-5 shrink-0" />
                            <div>
                                <span className="font-medium">IA inactiva.</span> Pega tu API key de Anthropic para activarla. La consigues en{' '}
                                <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noreferrer" className="underline">
                                    console.anthropic.com
                                </a>
                                .
                            </div>
                        </div>
                    )}

                    {flash?.success && (
                        <div className="rounded-lg border border-primary/30 bg-primary/10 px-4 py-2.5 text-sm font-medium text-primary">{flash.success}</div>
                    )}

                    <form onSubmit={save} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="api_key">API key de Anthropic</Label>
                            <div className="relative">
                                <KeyRound className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                                <Input
                                    id="api_key"
                                    type="password"
                                    value={data.api_key}
                                    onChange={(e) => setData('api_key', e.target.value)}
                                    placeholder={configured ? 'Pega una nueva clave para reemplazarla…' : 'sk-ant-...'}
                                    className="pl-9 font-mono"
                                    autoComplete="off"
                                />
                            </div>
                            <p className="text-muted-foreground text-xs">Se guarda cifrada. Déjala vacía para conservar la actual.</p>
                            <InputError message={errors.api_key} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="model">Modelo</Label>
                            <select
                                id="model"
                                value={data.model}
                                onChange={(e) => setData('model', e.target.value)}
                                className="border-input bg-background ring-offset-background focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                            >
                                {models.map((m) => (
                                    <option key={m} value={m}>
                                        {modelLabels[m] ?? m}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.model} />
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <Button type="submit" disabled={processing}>
                                {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                Guardar
                            </Button>
                            <Button type="button" variant="secondary" onClick={test} disabled={testing}>
                                {testing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Sparkles className="h-4 w-4" />}
                                Probar conexión
                            </Button>
                            {configured && !fromEnv && (
                                <Button type="button" variant="ghost" className="text-destructive hover:text-destructive" onClick={removeKey}>
                                    Eliminar clave
                                </Button>
                            )}
                        </div>
                    </form>

                    {/* Perfil de la clínica y persona del bot */}
                    <div className="border-t border-border/60 pt-8">
                        <HeadingSmall title="Perfil de la clínica y del bot" description="Estos datos alimentan al asistente: cómo se presenta y qué sabe de tu clínica." />
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                botForm.put(route('ai.bot'), { preserveScroll: true });
                            }}
                            className="mt-4 space-y-5"
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="clinic_name">Nombre de la clínica</Label>
                                <Input id="clinic_name" value={botForm.data.clinic_name} onChange={(e) => botForm.setData('clinic_name', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="clinic_address">Dirección</Label>
                                <Input id="clinic_address" value={botForm.data.clinic_address} onChange={(e) => botForm.setData('clinic_address', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="clinic_hours">Horarios</Label>
                                <Input id="clinic_hours" value={botForm.data.clinic_hours} onChange={(e) => botForm.setData('clinic_hours', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="clinic_payment">Formas de pago</Label>
                                <Input id="clinic_payment" value={botForm.data.clinic_payment} onChange={(e) => botForm.setData('clinic_payment', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="bot_persona">Instrucciones / tono del bot (opcional)</Label>
                                <Textarea
                                    id="bot_persona"
                                    value={botForm.data.bot_persona}
                                    onChange={(e) => botForm.setData('bot_persona', e.target.value)}
                                    rows={4}
                                    placeholder="Ej. Usa un tono muy cercano, tutea, y menciona que la Dra. Blanco tiene 10 años de experiencia…"
                                />
                            </div>
                            <Button type="submit" disabled={botForm.processing}>
                                {botForm.processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                Guardar perfil
                            </Button>
                        </form>
                    </div>

                    <div className="text-muted-foreground border-t border-border/60 pt-4 text-xs">
                        El consumo de la API lo cobra Anthropic directamente a tu cuenta. Este sistema solo usa tu clave para generar el contenido que solicites.
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
