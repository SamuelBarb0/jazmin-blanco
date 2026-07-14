import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { motion } from 'motion/react';
import { BookOpen, Check, LoaderCircle, Pencil, Plus, Trash2, X } from 'lucide-react';
import { useState } from 'react';

interface Entry {
    id: number;
    category: string;
    title: string;
    content: string;
    is_active: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Conocimiento', href: '/knowledge' }];

const categoryLabels: Record<string, string> = {
    faq: 'Pregunta frecuente',
    valoracion: 'Valoración',
    contraindicacion: 'Contraindicación',
    diferenciador: 'Diferenciador',
    politica: 'Política',
    ubicacion: 'Ubicación',
    pago: 'Pago',
    general: 'General',
};

export default function KnowledgeIndex({ entries, categories }: { entries: Entry[]; categories: string[] }) {
    const { flash } = usePage<SharedData>().props;
    const [editingId, setEditingId] = useState<number | null>(null);

    const { data, setData, post, put, processing, errors, reset } = useForm({
        category: 'faq',
        title: '',
        content: '',
        is_active: true as boolean,
    });

    const startEdit = (e: Entry) => {
        setEditingId(e.id);
        setData({ category: e.category, title: e.title, content: e.content, is_active: e.is_active });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const cancelEdit = () => {
        setEditingId(null);
        reset();
    };

    const submit = (ev: React.FormEvent) => {
        ev.preventDefault();
        if (editingId) {
            put(route('knowledge.update', editingId), { preserveScroll: true, onSuccess: () => cancelEdit() });
        } else {
            post(route('knowledge.store'), { preserveScroll: true, onSuccess: () => reset() });
        }
    };

    const destroy = (e: Entry) => {
        if (confirm(`¿Eliminar "${e.title}"?`)) {
            router.delete(route('knowledge.destroy', e.id), { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Base de conocimiento" />
            <div className="mx-auto flex h-full w-full max-w-4xl flex-col gap-6 p-4">
                <div>
                    <h1 className="font-display text-3xl tracking-tight">Base de conocimiento</h1>
                    <p className="text-muted-foreground">Lo que el bot sabe de tu clínica para responder a los pacientes.</p>
                </div>

                {flash?.success && <div className="rounded-lg border border-primary/30 bg-primary/10 px-4 py-2.5 text-sm font-medium text-primary">{flash.success}</div>}

                {/* Formulario */}
                <form onSubmit={submit} className="glass space-y-4 rounded-2xl p-5">
                    <HeadingSmall title={editingId ? 'Editar entrada' : 'Nueva entrada'} description="Una pregunta/tema y su respuesta. El bot la usará como fuente." />
                    <div className="grid gap-4 sm:grid-cols-[200px_1fr]">
                        <div className="grid gap-2">
                            <Label htmlFor="category">Categoría</Label>
                            <select
                                id="category"
                                value={data.category}
                                onChange={(e) => setData('category', e.target.value)}
                                className="border-input bg-background focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                            >
                                {categories.map((c) => (
                                    <option key={c} value={c}>
                                        {categoryLabels[c] ?? c}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="title">Título / pregunta</Label>
                            <Input id="title" value={data.title} onChange={(e) => setData('title', e.target.value)} placeholder="Ej. ¿Cuánto dura la recuperación?" />
                            <InputError message={errors.title} />
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="content">Contenido / respuesta</Label>
                        <Textarea id="content" value={data.content} onChange={(e) => setData('content', e.target.value)} rows={3} placeholder="La respuesta que el bot debe conocer…" />
                        <InputError message={errors.content} />
                    </div>
                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={processing}>
                            {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : editingId ? <Check className="h-4 w-4" /> : <Plus className="h-4 w-4" />}
                            {editingId ? 'Guardar cambios' : 'Agregar'}
                        </Button>
                        {editingId && (
                            <Button type="button" variant="ghost" onClick={cancelEdit}>
                                <X className="h-4 w-4" /> Cancelar
                            </Button>
                        )}
                    </div>
                </form>

                {/* Lista */}
                {entries.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-border/60 p-10 text-center">
                        <BookOpen className="text-muted-foreground size-7" />
                        <p className="text-muted-foreground text-sm">Aún no hay entradas. Agrega la primera arriba.</p>
                    </div>
                ) : (
                    <div className="flex flex-col gap-3">
                        {entries.map((e, i) => (
                            <motion.div
                                key={e.id}
                                initial={{ opacity: 0, y: 10 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ duration: 0.35, delay: Math.min(i * 0.04, 0.4) }}
                                className={cn('glass rounded-xl p-4', editingId === e.id && 'ring-primary/40 ring-2')}
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="mb-1 flex items-center gap-2">
                                            <Badge variant="outline" className="text-xs">{categoryLabels[e.category] ?? e.category}</Badge>
                                            {!e.is_active && <Badge variant="secondary" className="text-xs">Inactiva</Badge>}
                                        </div>
                                        <p className="font-medium">{e.title}</p>
                                        <p className="text-muted-foreground mt-0.5 text-sm">{e.content}</p>
                                    </div>
                                    <div className="flex shrink-0 gap-1">
                                        <Button variant="ghost" size="icon" className="size-8" onClick={() => startEdit(e)}>
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button variant="ghost" size="icon" className="text-destructive hover:text-destructive size-8" onClick={() => destroy(e)}>
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                </div>
                            </motion.div>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
