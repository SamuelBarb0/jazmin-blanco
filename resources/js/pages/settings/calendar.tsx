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
import { CalendarCheck2, CalendarX2, CheckCircle2, Copy, LoaderCircle, TriangleAlert } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Google Calendar', href: '/settings/calendar' }];

interface CalendarProps {
    configured: boolean;
    hasServiceAccount: boolean;
    serviceAccountEmail: string | null;
    calendarId: string | null;
    timezone: string;
    oauthAvailable: boolean;
    googleConnected: boolean;
    googleEmail: string | null;
}

export default function CalendarSettings({
    configured,
    hasServiceAccount,
    serviceAccountEmail,
    calendarId,
    timezone,
    oauthAvailable,
    googleConnected,
    googleEmail,
}: CalendarProps) {
    const { flash } = usePage<SharedData>().props;
    const [testing, setTesting] = useState(false);
    const [copied, setCopied] = useState(false);

    const connectGoogle = () => {
        window.location.href = route('calendar.google.connect');
    };

    const disconnectGoogle = () => {
        if (confirm('¿Desconectar tu Google Calendar? Las citas dejarán de sincronizarse.')) {
            router.delete(route('calendar.google.disconnect'), { preserveScroll: true });
        }
    };

    const { data, setData, put, processing, errors } = useForm({
        service_account_json: '',
        calendar_id: calendarId ?? '',
        timezone: timezone ?? 'America/Bogota',
    });

    const save: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('calendar.update'), { preserveScroll: true, onSuccess: () => setData('service_account_json', '') });
    };

    const test = () => {
        setTesting(true);
        router.post(route('calendar.test'), {}, { preserveScroll: true, preserveState: true, onFinish: () => setTesting(false) });
    };

    const disconnect = () => {
        if (confirm('¿Desconectar Google Calendar? Las citas dejarán de sincronizarse.')) {
            router.delete(route('calendar.destroy'), { preserveScroll: true });
        }
    };

    const copyEmail = () => {
        if (serviceAccountEmail) {
            navigator.clipboard.writeText(serviceAccountEmail);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Google Calendar" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Google Calendar"
                        description="Conecta una cuenta de servicio de Google para que cada cita agendada aparezca automáticamente en el calendario de la clínica."
                    />

                    {/* Estado */}
                    {configured ? (
                        <div className="flex items-center gap-3 rounded-xl border border-emerald-300/50 bg-emerald-50 px-4 py-3 text-sm dark:border-emerald-500/30 dark:bg-emerald-500/10">
                            <CalendarCheck2 className="size-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                            <div className="text-emerald-800 dark:text-emerald-300">
                                <span className="font-medium">Calendario conectado.</span> Las citas se sincronizan automáticamente.
                            </div>
                        </div>
                    ) : (
                        <div className="flex items-center gap-3 rounded-xl border border-amber-300/60 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                            <CalendarX2 className="size-5 shrink-0" />
                            <div>
                                <span className="font-medium">Sin conectar.</span> Pega la credencial de la cuenta de servicio y el ID del calendario para activar la sincronización.
                            </div>
                        </div>
                    )}

                    {flash?.success && (
                        <div className="rounded-lg border border-primary/30 bg-primary/10 px-4 py-2.5 text-sm font-medium text-primary">{flash.success}</div>
                    )}

                    {flash?.error && (
                        <div className="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/10 px-4 py-2.5 text-sm font-medium text-destructive">
                            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                            <span>{flash.error}</span>
                        </div>
                    )}

                    {/* Camino fácil: conectar el propio calendario con un clic (OAuth) */}
                    {oauthAvailable && (
                        <div className="rounded-xl border border-border bg-accent/40 p-4">
                            {googleConnected ? (
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div className="flex items-center gap-3">
                                        <CalendarCheck2 className="size-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                        <div className="text-sm">
                                            <p className="font-medium">Google Calendar conectado.</p>
                                            <p className="text-muted-foreground text-xs">
                                                Las citas se guardan en tu calendario «Citas Consultorio»
                                                {googleEmail ? ` · ${googleEmail}` : ''}.
                                            </p>
                                        </div>
                                    </div>
                                    <Button type="button" variant="ghost" className="text-destructive hover:text-destructive" onClick={disconnectGoogle}>
                                        Desconectar
                                    </Button>
                                </div>
                            ) : (
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div className="text-sm">
                                        <p className="font-medium">Conecta tu Google Calendar con un clic</p>
                                        <p className="text-muted-foreground text-xs">
                                            Inicia sesión con Google y autoriza el acceso. Crearemos un calendario aparte, «Citas Consultorio», para no mezclar las citas con tu agenda personal.
                                        </p>
                                    </div>
                                    <Button type="button" onClick={connectGoogle}>
                                        <CalendarCheck2 className="size-4" /> Conectar con Google
                                    </Button>
                                </div>
                            )}
                        </div>
                    )}

                    {/* Opción avanzada: cuenta de servicio */}
                    <div className="border-t border-border/60 pt-5">
                        <p className="text-muted-foreground mb-4 text-xs font-medium uppercase tracking-wide">
                            {oauthAvailable ? 'Opción avanzada — cuenta de servicio' : 'Conexión con cuenta de servicio'}
                        </p>
                    </div>

                    {/* Correo de la cuenta de servicio — compartir el calendario con él */}
                    {hasServiceAccount && serviceAccountEmail && (
                        <div className="rounded-xl border border-border/60 bg-muted/40 px-4 py-3 text-sm">
                            <p className="text-muted-foreground mb-1.5 text-xs">
                                Comparte el calendario de la clínica con este correo (permiso «Hacer cambios en los eventos»):
                            </p>
                            <div className="flex items-center gap-2">
                                <code className="bg-background flex-1 truncate rounded border border-border/60 px-2 py-1 font-mono text-xs">{serviceAccountEmail}</code>
                                <Button type="button" variant="outline" size="sm" onClick={copyEmail}>
                                    {copied ? <CheckCircle2 className="size-4 text-emerald-500" /> : <Copy className="size-4" />}
                                    {copied ? 'Copiado' : 'Copiar'}
                                </Button>
                            </div>
                        </div>
                    )}

                    <form onSubmit={save} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="service_account_json">Credencial JSON de la cuenta de servicio</Label>
                            <Textarea
                                id="service_account_json"
                                value={data.service_account_json}
                                onChange={(e) => setData('service_account_json', e.target.value)}
                                rows={5}
                                placeholder={hasServiceAccount ? 'Ya hay una credencial guardada. Pega una nueva solo si quieres reemplazarla…' : '{ "type": "service_account", "project_id": "...", ... }'}
                                className="font-mono text-xs"
                                autoComplete="off"
                            />
                            <p className="text-muted-foreground text-xs">Se guarda cifrada. Déjalo vacío para conservar la actual.</p>
                            <InputError message={errors.service_account_json} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="calendar_id">ID del calendario</Label>
                            <Input
                                id="calendar_id"
                                value={data.calendar_id}
                                onChange={(e) => setData('calendar_id', e.target.value)}
                                placeholder="xxxxx@group.calendar.google.com"
                                className="font-mono text-xs"
                            />
                            <p className="text-muted-foreground text-xs">
                                Lo encuentras en Google Calendar → Configuración del calendario → «Integrar el calendario».
                            </p>
                            <InputError message={errors.calendar_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="timezone">Zona horaria</Label>
                            <Input id="timezone" value={data.timezone} onChange={(e) => setData('timezone', e.target.value)} placeholder="America/Bogota" />
                            <InputError message={errors.timezone} />
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <Button type="submit" disabled={processing}>
                                {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                Guardar
                            </Button>
                            <Button type="button" variant="secondary" onClick={test} disabled={testing || !configured}>
                                {testing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <CalendarCheck2 className="h-4 w-4" />}
                                Probar conexión
                            </Button>
                            {configured && (
                                <Button type="button" variant="ghost" className="text-destructive hover:text-destructive" onClick={disconnect}>
                                    Desconectar
                                </Button>
                            )}
                        </div>
                        <InputError message={errors.calendar_id} />
                    </form>

                    <div className="text-muted-foreground border-t border-border/60 pt-4 text-xs">
                        Usamos una cuenta de servicio de Google: no necesitas iniciar sesión cada vez ni renovar permisos. Solo comparte el calendario con el correo de arriba.
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
