import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, CreditCard, FlaskConical, LoaderCircle, TriangleAlert } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Pagos', href: '/settings/pagos' }];

interface Props {
    connected: boolean;
    tokenHint: string | null;
    hasPublicKey: boolean;
    liveHint: string | null;
    hasLivePublicKey: boolean;
    testHint: string | null;
    hasTestPublicKey: boolean;
    testMode: boolean;
    valoracionAmount: number;
    methods: string[];
}

const etiquetaMedio: Record<string, string> = {
    credit_card: 'Tarjeta de crédito',
    debit_card: 'Tarjeta débito',
    bank_transfer: 'PSE',
    ticket: 'Efecty',
};

export default function PaymentSettings({
    connected,
    tokenHint,
    hasPublicKey,
    liveHint,
    hasLivePublicKey,
    testHint,
    hasTestPublicKey,
    testMode,
    valoracionAmount,
    methods,
}: Props) {
    const { flash } = usePage<SharedData>().props;
    const [testing, setTesting] = useState(false);

    const { data, setData, put, processing, errors } = useForm({
        access_token: '',
        public_key: '',
        test_access_token: '',
        test_public_key: '',
        test_mode: testMode,
        valoracion_amount: valoracionAmount,
    });

    const save: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('payments.update'), {
            preserveScroll: true,
            onSuccess: () => {
                setData('access_token', '');
                setData('public_key', '');
                setData('test_access_token', '');
                setData('test_public_key', '');
            },
        });
    };

    const test = () => {
        setTesting(true);
        router.post(route('payments.test'), {}, { preserveScroll: true, onFinish: () => setTesting(false) });
    };

    const desconectar = () => {
        if (confirm('¿Desconectar Mercado Pago? Se borran las credenciales de producción y las de prueba, y el asistente volverá a pedir el pago sin poder comprobarlo.')) {
            router.delete(route('payments.destroy'), { preserveScroll: true });
        }
    };

    const quitarPrueba = () => {
        if (confirm('¿Quitar las credenciales de prueba? Las de producción no se tocan.')) {
            router.delete(route('payments.destroyTest'), { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pagos" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Pagos en línea (Mercado Pago)"
                        description="Conecta tu cuenta de Mercado Pago para que el asistente genere un link de pago propio de cada paciente y confirme el pago antes de apartar el cupo."
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

                    {testMode && (
                        <div className="flex items-start gap-2 rounded-lg border-2 border-amber-500 bg-amber-500/15 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
                            <FlaskConical className="mt-0.5 size-4 shrink-0" />
                            <span>
                                <strong>MODO PRUEBA ACTIVO.</strong> Los links que envía el asistente NO cobran dinero real y solo se pagan con
                                tarjetas de prueba. Cualquier paciente que reciba uno ahora mismo no estará pagando de verdad. Apágalo cuando
                                termines de ensayar.
                            </span>
                        </div>
                    )}

                    {connected ? (
                        <div className="flex items-center gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm">
                            <CheckCircle2 className="size-4 text-emerald-600 dark:text-emerald-400" />
                            <span>
                                Mercado Pago conectado con la credencial <strong>{testMode ? 'de prueba' : 'de producción'}</strong>{' '}
                                <code className="rounded bg-muted px-1.5 py-0.5">{tokenHint}</code>
                                {hasPublicKey ? ' · public key guardada' : ' · sin public key (no hace falta para cobrar)'}
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

                    <form onSubmit={save} className="space-y-8">
                        <section className="space-y-5">
                            <div>
                                <h3 className="text-sm font-semibold">Credenciales de producción</h3>
                                <p className="text-xs text-muted-foreground">Las que cobran de verdad. Empiezan por <code>APP_USR-</code>.</p>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="access_token">Access token</Label>
                                <Input
                                    id="access_token"
                                    type="password"
                                    value={data.access_token}
                                    onChange={(e) => setData('access_token', e.target.value)}
                                    placeholder={liveHint ? `Guardado (${liveHint}). Pega uno nuevo para reemplazarlo…` : 'APP_USR-…'}
                                    autoComplete="off"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Está en mercadopago.com.co → Tu negocio → Configuración → Gestión y administración → Credenciales, pestaña{' '}
                                    <strong>Credenciales de producción</strong>. Se guarda cifrado.
                                </p>
                                <InputError message={errors.access_token} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="public_key">Public key (opcional)</Label>
                                <Input
                                    id="public_key"
                                    value={data.public_key}
                                    onChange={(e) => setData('public_key', e.target.value)}
                                    placeholder={hasLivePublicKey ? 'Guardada. Pega una nueva para reemplazarla…' : 'APP_USR-…'}
                                    autoComplete="off"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Hoy no es necesaria: la paciente paga abriendo el link desde WhatsApp. Sirve si algún día el cobro se hace dentro
                                    de la propia página.
                                </p>
                                <InputError message={errors.public_key} />
                            </div>
                        </section>

                        <section className="space-y-5 rounded-lg border border-border/60 bg-muted/20 p-4">
                            <div>
                                <h3 className="flex items-center gap-2 text-sm font-semibold">
                                    <FlaskConical className="size-4" />
                                    Credenciales de prueba
                                </h3>
                                <p className="text-xs text-muted-foreground">
                                    Empiezan por <code>TEST-</code> y se guardan aparte, sin tocar las de producción. Sirven para recorrer el circuito
                                    completo —el bot manda el link, alguien lo paga, el bot confirma— sin mover dinero.
                                </p>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="test_access_token">Access token de prueba</Label>
                                <Input
                                    id="test_access_token"
                                    type="password"
                                    value={data.test_access_token}
                                    onChange={(e) => setData('test_access_token', e.target.value)}
                                    placeholder={testHint ? `Guardado (${testHint}). Pega uno nuevo para reemplazarlo…` : 'TEST-…'}
                                    autoComplete="off"
                                />
                                <InputError message={errors.test_access_token} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="test_public_key">Public key de prueba (opcional)</Label>
                                <Input
                                    id="test_public_key"
                                    value={data.test_public_key}
                                    onChange={(e) => setData('test_public_key', e.target.value)}
                                    placeholder={hasTestPublicKey ? 'Guardada. Pega una nueva para reemplazarla…' : 'TEST-…'}
                                    autoComplete="off"
                                />
                                <InputError message={errors.test_public_key} />
                            </div>

                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="test_mode"
                                    checked={data.test_mode}
                                    onCheckedChange={(v) => setData('test_mode', v === true)}
                                    className="mt-0.5"
                                />
                                <div className="grid gap-1">
                                    <Label htmlFor="test_mode" className="cursor-pointer">
                                        Cobrar en modo prueba
                                    </Label>
                                    <p className="text-xs text-muted-foreground">
                                        Mientras esté activo, TODOS los links que genere el asistente serán de prueba y no cobrarán dinero real. Úsalo
                                        junto con la lista de números de prueba de la pantalla de WhatsApp, para que ninguna paciente real reciba un
                                        link falso.
                                    </p>
                                </div>
                            </div>

                            {testHint && (
                                <button type="button" onClick={quitarPrueba} className="text-xs text-destructive hover:underline">
                                    Quitar credenciales de prueba
                                </button>
                            )}
                        </section>

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
                                basta: no hay que crear nada en Mercado Pago.
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
                                    Desconectar Mercado Pago
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
                            Mercado Pago no se pueden verificar.
                        </p>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
