import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { KeyRound, Trash2, UserPlus } from 'lucide-react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Equipo', href: '/settings/equipo' }];

type Miembro = {
    id: number;
    name: string;
    email: string;
    es_propietario: boolean;
    activo: boolean;
    created_at: string;
};

export default function Equipo({ miembros, yo }: { miembros: Miembro[]; yo: number }) {
    const { flash } = usePage<SharedData & { flash: { success?: string; clave_generada?: string | null } }>().props;

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
    });

    const invitar: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('equipo.store'), { preserveScroll: true, onSuccess: () => reset() });
    };

    const alternar = (m: Miembro) => {
        const pregunta = m.activo
            ? `¿Quitarle el acceso a ${m.name}? Podrás devolvérselo cuando quieras.`
            : `¿Devolverle el acceso a ${m.name}?`;
        if (confirm(pregunta)) {
            router.patch(route('equipo.toggle', m.id), {}, { preserveScroll: true });
        }
    };

    const eliminar = (m: Miembro) => {
        if (confirm(`¿Eliminar la cuenta de ${m.name}? Si solo quieres cerrarle el paso, es mejor quitarle el acceso.`)) {
            router.delete(route('equipo.destroy', m.id), { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Equipo" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Equipo de la clínica"
                        description="Quién más puede entrar al CRM. Todos trabajan sobre las mismas pacientes, la misma agenda y el mismo bot."
                    />

                    {flash?.success && (
                        <div className="rounded-lg border border-primary/30 bg-primary/10 px-4 py-3 text-sm font-medium text-primary">
                            {flash.success}
                        </div>
                    )}

                    {/* La contraseña generada se enseña UNA vez: no se guarda en
                        claro en ningún sitio, así que si se pierde hay que
                        generar otra. */}
                    {flash?.clave_generada && (
                        <div className="rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm">
                            <p className="mb-1 flex items-center gap-2 font-medium text-amber-700 dark:text-amber-400">
                                <KeyRound className="size-4" /> Contraseña temporal
                            </p>
                            <code className="font-mono text-base select-all">{flash.clave_generada}</code>
                            <p className="mt-2 text-muted-foreground">
                                Cópiala ahora y entrégasela por un medio seguro. No vuelve a mostrarse. Pídele que la cambie al entrar.
                            </p>
                        </div>
                    )}

                    <div className="overflow-x-auto rounded-xl border border-border/60">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th className="px-4 py-2 text-left font-medium">Persona</th>
                                    <th className="px-4 py-2 text-left font-medium">Acceso</th>
                                    <th className="px-4 py-2 text-right font-medium">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                {miembros.map((m) => (
                                    <tr key={m.id} className="border-t border-border/40">
                                        <td className="px-4 py-3">
                                            <p className="font-medium">
                                                {m.name}
                                                {m.id === yo && <span className="text-muted-foreground"> · tú</span>}
                                            </p>
                                            <p className="text-muted-foreground text-xs">{m.email}</p>
                                        </td>
                                        <td className="px-4 py-3">
                                            {m.es_propietario ? (
                                                <span className="rounded-full bg-primary/15 px-2 py-0.5 text-xs font-medium text-primary">
                                                    Dueña de la clínica
                                                </span>
                                            ) : m.activo ? (
                                                <span className="rounded-full bg-emerald-500/15 px-2 py-0.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                                                    Activo
                                                </span>
                                            ) : (
                                                <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                                                    Sin acceso
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {!m.es_propietario && (
                                                <div className="flex justify-end gap-2">
                                                    <Button variant="outline" size="sm" onClick={() => alternar(m)}>
                                                        {m.activo ? 'Quitar acceso' : 'Devolver acceso'}
                                                    </Button>
                                                    <Button variant="ghost" size="sm" className="text-destructive" onClick={() => eliminar(m)}>
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <form onSubmit={invitar} className="space-y-4 rounded-xl border border-border/60 p-4">
                        <p className="flex items-center gap-2 font-medium">
                            <UserPlus className="size-4" /> Dar acceso a alguien más
                        </p>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nombre</Label>
                                <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Correo</Label>
                                <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} required />
                                <InputError message={errors.email} />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">Contraseña (opcional)</Label>
                            <Input
                                id="password"
                                type="text"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="Si lo dejas vacío, se genera una y se muestra aquí"
                            />
                            <InputError message={errors.password} />
                        </div>

                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creando…' : 'Crear acceso'}
                        </Button>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
