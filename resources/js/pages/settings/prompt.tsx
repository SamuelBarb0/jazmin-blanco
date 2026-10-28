import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, ClipboardCopy, RotateCcw } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Instrucciones de Lore', href: '/settings/prompt' }];

interface Token {
    etiqueta: string;
    descripcion: string;
}

interface PromptProps {
    prompt: string;
    default: string;
    personalizado: boolean;
    tokens: Token[];
}

export default function PromptSettings({ prompt, default: original, personalizado, tokens }: PromptProps) {
    const { flash } = usePage<SharedData>().props;
    const [copiado, setCopiado] = useState(false);

    const form = useForm<{ prompt: string }>({ prompt });

    const guardar: FormEventHandler = (e) => {
        e.preventDefault();
        if (
            !confirm(
                'Vas a cambiar las instrucciones que gobiernan cómo responde Lore a TODAS las pacientes.\n\n' +
                    'Te recomendamos haber guardado una copia del texto original antes de continuar.\n\n' +
                    '¿Guardar los cambios?',
            )
        ) {
            return;
        }
        form.put(route('prompt.update'), { preserveScroll: true });
    };

    const restaurar = () => {
        if (confirm('¿Restaurar el texto ORIGINAL de Lore? Se perderán los cambios que hayas guardado.')) {
            router.delete(route('prompt.reset'), { preserveScroll: true });
        }
    };

    const copiarOriginal = () => {
        navigator.clipboard?.writeText(original).then(
            () => {
                setCopiado(true);
                setTimeout(() => setCopiado(false), 2500);
            },
            () => {},
        );
    };

    const modificado = form.data.prompt !== prompt;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Instrucciones de Lore" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Instrucciones de Lore"
                        description="Este es el texto que le dice a Lore cómo comportarse: su tono, sus reglas y qué puede y qué no puede decir."
                    />

                    {flash?.success && (
                        <div className="flex items-start gap-3 rounded-xl border border-emerald-300/50 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                            <CheckCircle2 className="mt-0.5 size-5 shrink-0" />
                            <span>{flash.success}</span>
                        </div>
                    )}

                    {/* Aviso permanente: esto es el texto original que hace funcionar a Lore. */}
                    <div className="rounded-xl border border-amber-300/60 bg-amber-50 px-4 py-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                        <div className="flex items-start gap-3">
                            <AlertTriangle className="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                            <div className="space-y-2">
                                <p className="font-medium">Este es el texto original que hace funcionar a Lore.</p>
                                <ul className="list-disc space-y-1 pl-4">
                                    <li>
                                        Cada cambio que guardes modifica cómo responde Lore a <strong>todas</strong> tus pacientes, de inmediato.
                                    </li>
                                    <li>
                                        Antes de editar, te recomendamos <strong>copiar y guardar el texto original</strong> (botón de abajo), por si quieres volver a él.
                                    </li>
                                    <li>
                                        No borres las etiquetas entre llaves como <code className="rounded bg-amber-200/50 px-1 dark:bg-amber-500/20">{'{{base_conocimiento}}'}</code>: si quitas una, Lore pierde esa información (los precios, los servicios, los horarios…).
                                    </li>
                                    <li>Si algo sale mal, puedes volver al original en cualquier momento con “Restaurar el original”.</li>
                                </ul>
                                <div className="flex flex-wrap gap-2 pt-1">
                                    <Button type="button" size="sm" variant="outline" onClick={copiarOriginal}>
                                        <ClipboardCopy className="size-4" />
                                        {copiado ? '¡Copiado!' : 'Copiar el texto original'}
                                    </Button>
                                    <Button type="button" size="sm" variant="outline" onClick={restaurar} disabled={!personalizado}>
                                        <RotateCcw className="size-4" />
                                        Restaurar el original
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="text-sm text-muted-foreground">
                        {personalizado ? (
                            <span className="inline-flex items-center gap-2 rounded-full bg-muted px-3 py-1 font-medium">
                                Estás usando una versión personalizada
                            </span>
                        ) : (
                            <span className="inline-flex items-center gap-2 rounded-full bg-muted px-3 py-1 font-medium">
                                Estás usando el texto original
                            </span>
                        )}
                    </div>

                    <form onSubmit={guardar} className="space-y-4">
                        <Textarea
                            value={form.data.prompt}
                            onChange={(e) => form.setData('prompt', e.target.value)}
                            rows={28}
                            spellCheck={false}
                            className="font-mono text-xs leading-relaxed"
                        />
                        <InputError message={form.errors.prompt} />

                        <div className="flex items-center gap-3">
                            <Button type="submit" disabled={form.processing || !modificado}>
                                Guardar cambios
                            </Button>
                            {modificado && <span className="text-sm text-muted-foreground">Tienes cambios sin guardar.</span>}
                        </div>
                    </form>

                    {/* Referencia de etiquetas disponibles */}
                    <div className="rounded-xl border px-4 py-4">
                        <p className="mb-3 text-sm font-medium">Etiquetas que puedes usar en el texto</p>
                        <p className="mb-3 text-sm text-muted-foreground">
                            El sistema reemplaza estas etiquetas por los datos reales cuando Lore responde. Déjalas tal cual donde quieras que aparezca
                            cada dato.
                        </p>
                        <dl className="space-y-2 text-sm">
                            {tokens.map((t) => (
                                <div key={t.etiqueta} className="flex flex-col gap-0.5 sm:flex-row sm:gap-3">
                                    <dt className="shrink-0 sm:w-56">
                                        <code className="rounded bg-muted px-1.5 py-0.5 text-xs">{t.etiqueta}</code>
                                    </dt>
                                    <dd className="text-muted-foreground">{t.descripcion}</dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
