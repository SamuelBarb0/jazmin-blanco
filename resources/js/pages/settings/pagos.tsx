import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, CreditCard, LoaderCircle, TriangleAlert } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Pagos', href: '/settings/pagos' }];

interface Props {
    connected: boolean;
    identityHint: string | null;
    hasSecret: boolean;
    valoracionAmount: number;
    methods: string[];
}

const etiquetaMedio: Record<string, string> = {
    CREDIT_CARD: 'Tarjeta',
    PSE: 'PSE',
    BOTON_BANCOLOMBIA: 'Botón Bancolombia',
    NEQUI: 'Nequi',
};

export default function PaymentSettings({ connected, identityHint, hasSecret, valoracionAmount, methods }: Props) {
    const { flash } = usePage<SharedData>().props;
    const [testing, setTesting] = useState(false);

    const { data, setData, put, processing, errors } = useForm({
        identity_key: '',
        secret_key: '',
        valoracion_amount: valoracionAmount,
    });

    const save: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('payments.update'), {
            preserveScroll: true,
            onSuccess: () => {
                setData('identity_key', '');
                setData('secret_key', '');
            },
        });
    };

    const test = () => {
        setTesting(true);
        router.post(route('payments.test'), {}, { preserveScroll: true, onFinish: () => setTesting(false) });
    };

    const desconectar = () => {
        if (confirm('¿Desconectar Bold? El asistente volverá a pedir el pago sin poder comprobarlo.')) {
            router.delete(route('payments.destroy'), { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pagos" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Pagos en línea (Bold)"
                        description="Conecta tu cuenta de Bold para que el asistente genere un link de pago propio de cada paciente y confirme el pago antes de apartar el cupo."
                    />

                    {flash?.success && (
                        <div className="rounded-lg border border-primary/30 bg-primary/10 px-4 py-3 text-sm font-medium text-primary">{flash.success}</div>
                    )}
                    {flash?.error && (
                        <div className="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                            {flash.error}
                        </div>
                    )}

                    {connected ? (
                        <div className="flex items-center gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm">
                            <CheckCircle2 className="size-4 text-emerald-600 dark:text-emerald-400" />
                            <span>
                                Bold conectado. Llave de identidad <code className="rounded bg-muted px-1.5 py-0.5">{identityHint}</code>
                                {hasSecret ? ' · llave secreta guardada' : ' · sin llave secreta (no hace falta para cobrar)'}
                            </span>
                        </div>
                    ) : (
                        <div className="flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 dark:text-amber-300">
                            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                            <span>
                                Sin conectar. Mientras tanto el asistente pide el pago pero no puede comprobarlo: le cree a la paciente cuando dice que
                                ya pagó.
                            </span>
                        </div>
                    )}

                    <form onSubmit={save} className="space-y-5">
                        <div className="grid gap-2">
                            <Label htmlFor="identity_key">Llave de identidad</Label>
                            <Input
                                id="identity_key"
                                value={data.identity_key}
                                onChange={(e) => setData('identity_key', e.target.value)}
                                placeholder={connected ? 'Pega una nueva llave para reemplazarla…' : 'Llave de identidad de Bold'}
                                autoComplete="off"
                            />
                            <p className="text-xs text-muted-foreground">
                                Está en panel.bold.co → Integraciones → Llaves de integración → API pagos en línea. Usa la de <strong>pruebas</strong>{' '}
                                para ensayar sin dinero real, o la de <strong>producción</strong> para cobrar de verdad.
                            </p>
                            <InputError message={errors.identity_key} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="secret_key">Llave secreta (opcional)</Label>
                            <Input
                                id="secret_key"
                                type="password"
                                value={data.secret_key}
                                onChange={(e) => setData('secret_key', e.target.value)}
                                placeholder={hasSecret ? 'Guardada. Pega una nueva para reemplazarla…' : 'Llave secreta de Bold'}
                                autoComplete="off"
                            />
                            <p className="text-xs text-muted-foreground">
                                Se guarda cifrada. Hoy no es necesaria para cobrar; sirve para validar las notificaciones automáticas de Bold si algún
                                día se activan.
                            </p>
                            <InputError message={errors.secret_key} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="valoracion_amount">Valor de la valoración</Label>
                            <Input
                                id="valoracion_amount"
                                type="number"
                                min={1000}
                                step={1000}
                                value={data.valoracion_amount}
                                onChange={(e) => setData('valoracion_amount', Number(e.target.value))}
                            />
                            <p className="text-xs text-muted-foreground">
                                Es lo que se le cobra a la paciente para apartar el cupo. Cada link se genera por este monto, así que cambiarlo aquí
                                basta: ya no hay que crear un link nuevo en Bold.
                            </p>
                            <InputError message={errors.valoracion_amount} />
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <Button type="submit" disabled={processing}>
                                {processing && <LoaderCircle className="size-4 animate-spin" />}
                                Guardar
                            </Button>

                            <Button type="button" variant="outline" onClick={test} disabled={testing || !connected}>
                                {testing ? <LoaderCircle className="size-4 animate-spin" /> : <CreditCard className="size-4" />}
                                Probar conexión
                            </Button>

                            {connected && (
                                <button type="button" onClick={desconectar} className="text-sm text-destructive hover:underline">
                                    Desconectar Bold
                                </button>
                            )}
                        </div>
                    </form>

                    <div className="rounded-lg border border-border/60 bg-muted/30 px-4 py-3 text-sm">
                        <p className="font-medium">Medios de pago que verá la paciente</p>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {methods.map((m) => (
                                <span key={m} className="rounded-full bg-background px-2.5 py-1 text-xs">
                                    {etiquetaMedio[m] ?? m}
                                </span>
                            ))}
                        </div>
                        <p className="mt-3 text-xs text-muted-foreground">
                            Al pagar por el link, cualquiera de estos medios queda confirmado automáticamente. Las transferencias hechas por fuera de
                            Bold no se pueden verificar.
                        </p>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
