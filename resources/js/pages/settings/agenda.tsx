import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CalendarOff, TriangleAlert, Undo2 } from 'lucide-react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Días sin atención', href: '/settings/agenda' }];

type DiaCerrado = {
    fecha: string;
    motivo: string;
    etiqueta: string;
    citas: number;
};

export default function Agenda({ dias }: { dias: DiaCerrado[] }) {
    const { flash } = usePage<SharedData & { flash: { success?: string; aviso_citas?: string | null } }>().props;

    const { data, setData, post, processing, errors, reset } = useForm({
        fecha: '',
        hasta: '',
        motivo: '',
    });

    const cerrar: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('agenda.store'), { preserveScroll: true, onSuccess: () => reset() });
    };

    const reabrir = (d: DiaCerrado) => {
        const aviso =
            d.citas > 0
                ? `${d.etiqueta} tiene ${d.citas} cita(s) agendada(s). ¿Volver a abrirlo?`
                : `¿Volver a abrir el ${d.etiqueta}?`;
        if (confirm(aviso)) {
            router.delete(route('agenda.destroy', d.fecha), { preserveScroll: true });
        }
    };

    const hoy = new Date().toISOString().slice(0, 10);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Días sin atención" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Días sin atención"
                        description="Festivos, vacaciones o cualquier día suelto en que el consultorio no abre. Lore deja de ofrecerlos y no agenda en ellos aunque la paciente los pida."
                    />

                    {flash?.success && (
                        <div className="rounded-lg border border-primary/30 bg-primary/10 px-4 py-3 text-sm font-medium text-primary">
                            {flash.success}
                        </div>
                    )}

                    {/* Cerrar un día NO cancela lo que ya estaba agendado. Si no se
                        dijera, se descubriría el día que una paciente llegue a un
                        consultorio cerrado. */}
                    {flash?.aviso_citas && (
                        <div className="flex gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 dark:text-amber-400">
                            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                            <span>{flash.aviso_citas}</span>
                        </div>
                    )}

                    <form onSubmit={cerrar} className="space-y-4 rounded-lg border p-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="fecha">Desde</Label>
                                <Input
                                    id="fecha"
                                    type="date"
                                    min={hoy}
                                    value={data.fecha}
                                    onChange={(e) => setData('fecha', e.target.value)}
                                    required
                                />
                                <InputError message={errors.fecha} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="hasta">Hasta (opcional)</Label>
                                <Input
                                    id="hasta"
                                    type="date"
                                    min={data.fecha || hoy}
                                    value={data.hasta}
                                    onChange={(e) => setData('hasta', e.target.value)}
                                />
                                <InputError message={errors.hasta} />
                                <p className="text-xs text-muted-foreground">Déjalo vacío para cerrar un solo día.</p>
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="motivo">Motivo (opcional)</Label>
                            <Input
                                id="motivo"
                                value={data.motivo}
                                onChange={(e) => setData('motivo', e.target.value)}
                                placeholder="Vacaciones, congreso, festivo…"
                            />
                            <InputError message={errors.motivo} />
                            <p className="text-xs text-muted-foreground">
                                Solo lo ves tú. A la paciente, Lore le dice únicamente que ese día está cerrado.
                            </p>
                        </div>

                        <Button type="submit" disabled={processing}>
                            <CalendarOff className="size-4" />
                            Cerrar estos días
                        </Button>
                    </form>

                    <div className="space-y-2">
                        {dias.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No hay ningún día cerrado. La agenda sigue el horario normal de la semana.
                            </p>
                        ) : (
                            dias.map((d) => (
                                <div key={d.fecha} className="flex items-center justify-between gap-3 rounded-lg border px-4 py-3">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium">{d.etiqueta}</p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {d.motivo || 'Sin motivo anotado'}
                                            {d.citas > 0 && (
                                                <span className="ml-2 text-amber-600 dark:text-amber-400">
                                                    · {d.citas} cita(s) ya agendada(s)
                                                </span>
                                            )}
                                        </p>
                                    </div>
                                    <Button variant="ghost" size="sm" onClick={() => reabrir(d)}>
                                        <Undo2 className="size-4" />
                                        Reabrir
                                    </Button>
                                </div>
                            ))
                        )}
                    </div>

                    <p className="text-xs text-muted-foreground">
                        Esto solo frena a Lore. Tú puedes seguir creando una cita a mano en un día cerrado si necesitas una excepción.
                    </p>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
