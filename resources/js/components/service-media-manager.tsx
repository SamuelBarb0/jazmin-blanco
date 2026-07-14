import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type Service, type ServiceMedia } from '@/types';
import { router, useForm } from '@inertiajs/react';
import { Film, ImageIcon, Link2, LoaderCircle, Trash2, Upload } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface MediaFormData {
    type: 'image' | 'video';
    caption: string;
    file: File | null;
    url: string;
    [key: string]: string | File | null;
}

export default function ServiceMediaManager({ service, media }: { service: Service; media: ServiceMedia[] }) {
    const [source, setSource] = useState<'file' | 'url'>('file');

    const { data, setData, post, processing, errors, reset } = useForm<MediaFormData>({
        type: 'image',
        caption: '',
        file: null,
        url: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('services.media.store', service.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setSource('file');
            },
        });
    };

    const remove = (id: number) => {
        router.delete(route('services.media.destroy', id), { preserveScroll: true });
    };

    return (
        <div className="rounded-xl border border-border bg-accent/40 p-4">
            <div className="mb-1 flex items-center gap-2">
                <ImageIcon className="size-4 text-primary" />
                <Label className="text-base">Fotos y videos</Label>
            </div>
            <p className="text-muted-foreground mb-4 text-sm">
                El asistente los enviará automáticamente cuando un paciente pida ver resultados de este servicio.
            </p>

            {/* Galería actual */}
            {media.length > 0 ? (
                <div className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    {media.map((m) => (
                        <div key={m.id} className="group relative overflow-hidden rounded-lg border border-border/60 bg-background">
                            {m.type === 'image' ? (
                                <img src={m.resolved_url ?? ''} alt={m.caption ?? ''} className="aspect-square w-full object-cover" />
                            ) : (
                                <video src={m.resolved_url ?? ''} className="aspect-square w-full bg-black object-cover" controls preload="metadata" />
                            )}
                            <div className="flex items-center justify-between gap-1 px-2 py-1.5">
                                <span className="text-muted-foreground flex items-center gap-1 truncate text-xs">
                                    {m.type === 'image' ? <ImageIcon className="size-3 shrink-0" /> : <Film className="size-3 shrink-0" />}
                                    <span className="truncate">{m.caption || (m.type === 'image' ? 'Foto' : 'Video')}</span>
                                </span>
                                <button
                                    type="button"
                                    onClick={() => remove(m.id)}
                                    className="text-muted-foreground hover:text-destructive shrink-0 transition"
                                    title="Eliminar"
                                >
                                    <Trash2 className="size-3.5" />
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
            ) : (
                <p className="text-muted-foreground mb-5 rounded-lg border border-dashed border-border/60 px-3 py-6 text-center text-sm">
                    Aún no hay material visual para este servicio.
                </p>
            )}

            {/* Alta de nuevo material */}
            <form onSubmit={submit} className="flex flex-col gap-3 border-t border-border/60 pt-4">
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="grid gap-1.5">
                        <Label htmlFor="media_type">Tipo</Label>
                        <select
                            id="media_type"
                            value={data.type}
                            onChange={(e) => setData('type', e.target.value as 'image' | 'video')}
                            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        >
                            <option value="image">Foto</option>
                            <option value="video">Video</option>
                        </select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Origen</Label>
                        <div className="flex gap-1.5">
                            <Button type="button" size="sm" variant={source === 'file' ? 'secondary' : 'ghost'} onClick={() => setSource('file')}>
                                <Upload className="size-4" /> Subir
                            </Button>
                            <Button type="button" size="sm" variant={source === 'url' ? 'secondary' : 'ghost'} onClick={() => setSource('url')}>
                                <Link2 className="size-4" /> URL
                            </Button>
                        </div>
                    </div>
                </div>

                {source === 'file' ? (
                    <div className="grid gap-1.5">
                        <Label htmlFor="media_file">Archivo</Label>
                        <Input
                            id="media_file"
                            type="file"
                            accept={data.type === 'image' ? 'image/*' : 'video/*'}
                            onChange={(e) => setData('file', e.target.files?.[0] ?? null)}
                        />
                        <InputError message={errors.file} />
                        <p className="text-muted-foreground text-xs">Máx. 50 MB. Fotos: jpg, png, webp. Videos: mp4, webm, mov.</p>
                    </div>
                ) : (
                    <div className="grid gap-1.5">
                        <Label htmlFor="media_url">URL pública del archivo</Label>
                        <Input
                            id="media_url"
                            type="url"
                            value={data.url}
                            onChange={(e) => setData('url', e.target.value)}
                            placeholder="https://…/foto.jpg"
                        />
                        <InputError message={errors.url} />
                    </div>
                )}

                <div className="grid gap-1.5">
                    <Label htmlFor="media_caption">Descripción (opcional)</Label>
                    <Input
                        id="media_caption"
                        value={data.caption}
                        onChange={(e) => setData('caption', e.target.value)}
                        placeholder="Ej. Resultado a las 4 semanas"
                    />
                    <InputError message={errors.caption} />
                </div>

                <div>
                    <Button type="submit" size="sm" disabled={processing}>
                        {processing ? <LoaderCircle className="size-4 animate-spin" /> : <Upload className="size-4" />}
                        Agregar material
                    </Button>
                </div>
            </form>
        </div>
    );
}
