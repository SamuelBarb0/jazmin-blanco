import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Bot, Clock, FileText, MessageCircle, Paperclip, Pause, Play, SendHorizonal, User, X } from 'lucide-react';
import { motion } from 'motion/react';
import { useEffect, useRef } from 'react';

interface ChatMedia {
    type: 'image' | 'video' | 'audio' | 'document';
    url: string | null;
    caption?: string;
    filename?: string;
}

interface Msg {
    id: number;
    role: 'user' | 'assistant';
    sent_by: 'bot' | 'human' | null;
    content: string;
    media?: ChatMedia[] | null;
    created_at: string | null;
}

interface LeadRef {
    id: number;
    name: string | null;
    phone: string | null;
}

interface ConversationRow {
    id: number;
    title: string;
    lead: LeadRef | null;
    channel: string;
    bot_enabled: boolean;
    last_message_at: string | null;
    preview: string | null;
}

interface Selected {
    id: number;
    title: string;
    channel: string;
    bot_enabled: boolean;
    bot_paused_at: string | null;
    lead: LeadRef | null;
    window_open: boolean;
    window_closes_at: string | null;
    messages: Msg[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Conversaciones', href: '/inbox' }];

/** "hace 5 min", "hace 2 h", "ayer" — suficiente para una bandeja. */
const hace = (iso: string | null): string => {
    if (!iso) return '';
    const min = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
    if (min < 1) return 'ahora';
    if (min < 60) return `hace ${min} min`;
    const h = Math.floor(min / 60);
    if (h < 24) return `hace ${h} h`;
    const d = Math.floor(h / 24);
    return d === 1 ? 'ayer' : `hace ${d} días`;
};

const hora = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleTimeString('es-CO', { hour: 'numeric', minute: '2-digit' }) : '';

export default function Inbox({ conversations, selected }: { conversations: ConversationRow[]; selected: Selected | null }) {
    const { flash } = usePage<SharedData>().props;
    const scrollRef = useRef<HTMLDivElement>(null);
    const archivoRef = useRef<HTMLInputElement>(null);
    const { data, setData, post, processing, reset } = useForm<{ content: string; archivo: File | null }>({
        content: '',
        archivo: null,
    });

    useEffect(() => {
        scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight });
    }, [selected?.id, selected?.messages.length]);

    // La bandeja se refresca sola: no hay tiempo real, pero una paciente que
    // escribe aparece en menos de un minuto sin tocar nada.
    useEffect(() => {
        const id = setInterval(() => {
            router.reload({ only: ['conversations', 'selected'] });
        }, 20000);

        return () => clearInterval(id);
    }, []);

    const quitarArchivo = () => {
        setData('archivo', null);
        if (archivoRef.current) archivoRef.current.value = '';
    };

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selected) return;
        // Con adjunto basta: la imagen puede ir sin texto.
        if (!data.content.trim() && !data.archivo) return;

        post(route('inbox.send', selected.id), {
            preserveScroll: true,
            // Obligatorio para que el archivo viaje como multipart.
            forceFormData: true,
            onSuccess: () => {
                reset('content', 'archivo');
                if (archivoRef.current) archivoRef.current.value = '';
            },
        });
    };

    const puedeEscribir = !!selected && selected.window_open && !!selected.lead?.phone;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Conversaciones" />

            <div className="flex h-[calc(100vh-9rem)] gap-4 p-4">
                {/* ── Lista de chats ─────────────────────────────── */}
                <aside className="glass flex w-80 shrink-0 flex-col overflow-hidden rounded-xl">
                    <header className="border-b border-border/60 px-4 py-3">
                        <h2 className="font-display text-lg">Conversaciones</h2>
                        <p className="text-xs text-muted-foreground">{conversations.length} chats de WhatsApp</p>
                    </header>

                    <div className="flex-1 overflow-y-auto">
                        {conversations.length === 0 && (
                            <p className="p-4 text-sm text-muted-foreground">
                                Todavía no hay conversaciones. Aparecerán aquí en cuanto una paciente escriba por WhatsApp.
                            </p>
                        )}

                        {conversations.map((c) => (
                            <button
                                key={c.id}
                                onClick={() => router.get(route('inbox.show', c.id), {}, { preserveState: true })}
                                className={cn(
                                    'flex w-full flex-col gap-1 border-b border-border/40 px-4 py-3 text-left transition hover:bg-muted/50',
                                    selected?.id === c.id && 'bg-muted',
                                )}
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <span className="truncate text-sm font-medium">{c.lead?.name || c.title}</span>
                                    <span className="shrink-0 text-[11px] text-muted-foreground">{hace(c.last_message_at)}</span>
                                </div>
                                <span className="truncate text-xs text-muted-foreground">{c.preview || 'Sin mensajes'}</span>
                                {!c.bot_enabled && (
                                    <span className="inline-flex w-fit items-center gap-1 rounded-full bg-amber-500/15 px-2 py-0.5 text-[10px] font-medium text-amber-600 dark:text-amber-400">
                                        <Pause className="size-2.5" /> Lore en pausa
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>
                </aside>

                {/* ── Chat ───────────────────────────────────────── */}
                <section className="glass flex flex-1 flex-col overflow-hidden rounded-xl">
                    {!selected && (
                        <div className="flex flex-1 items-center justify-center text-sm text-muted-foreground">
                            <MessageCircle className="mr-2 size-4" /> Elige una conversación
                        </div>
                    )}

                    {selected && (
                        <>
                            <header className="flex items-center justify-between gap-4 border-b border-border/60 px-5 py-3">
                                <div className="min-w-0">
                                    <h2 className="truncate font-display text-lg">{selected.lead?.name || selected.title}</h2>
                                    <p className="text-xs text-muted-foreground">{selected.lead?.phone || 'Sin teléfono'}</p>
                                </div>

                                <Button
                                    variant={selected.bot_enabled ? 'outline' : 'default'}
                                    size="sm"
                                    onClick={() => router.patch(route('inbox.toggle', selected.id), {}, { preserveScroll: true })}
                                >
                                    {selected.bot_enabled ? (
                                        <>
                                            <Pause className="size-4" /> Pausar a Lore
                                        </>
                                    ) : (
                                        <>
                                            <Play className="size-4" /> Reactivar a Lore
                                        </>
                                    )}
                                </Button>
                            </header>

                            {!selected.bot_enabled && (
                                <div className="flex items-center gap-2 border-b border-amber-500/30 bg-amber-500/10 px-5 py-2 text-xs text-amber-700 dark:text-amber-300">
                                    <AlertTriangle className="size-3.5 shrink-0" />
                                    Lore no está respondiendo en este chat. Las pacientes solo reciben lo que escribas tú.
                                </div>
                            )}

                            <div ref={scrollRef} className="flex-1 space-y-3 overflow-y-auto px-5 py-4">
                                {selected.messages.map((m) => {
                                    const mio = m.role === 'assistant';

                                    return (
                                        <motion.div
                                            key={m.id}
                                            initial={{ opacity: 0, y: 6 }}
                                            animate={{ opacity: 1, y: 0 }}
                                            className={cn('flex', mio ? 'justify-end' : 'justify-start')}
                                        >
                                            <div className={cn('max-w-[75%] space-y-2', mio && 'items-end')}>
                                                <div
                                                    className={cn(
                                                        'rounded-2xl px-4 py-2.5 text-sm whitespace-pre-wrap',
                                                        mio ? 'bg-primary text-primary-foreground rounded-br-md' : 'liquid-glass rounded-bl-md',
                                                    )}
                                                >
                                                    {m.content}
                                                </div>

                                                {m.media?.map((media, i) =>
                                                    media.url ? (
                                                        <div key={i} className="overflow-hidden rounded-xl">
                                                            {media.type === 'video' ? (
                                                                <video src={media.url} controls className="max-h-64 w-full" />
                                                            ) : media.type === 'audio' ? (
                                                                // Las notas de voz llegan en ogg/opus: el navegador las reproduce.
                                                                <audio src={media.url} controls className="w-full" />
                                                            ) : media.type === 'document' ? (
                                                                <a
                                                                    href={media.url}
                                                                    target="_blank"
                                                                    rel="noreferrer"
                                                                    className="liquid-glass flex items-center gap-2 rounded-xl px-3 py-2 text-xs hover:underline"
                                                                >
                                                                    <FileText className="size-4 shrink-0" />
                                                                    <span className="truncate">{media.filename || 'Abrir archivo'}</span>
                                                                </a>
                                                            ) : (
                                                                <a href={media.url} target="_blank" rel="noreferrer">
                                                                    <img
                                                                        src={media.url}
                                                                        alt={media.caption ?? ''}
                                                                        className="max-h-64 w-full object-cover"
                                                                    />
                                                                </a>
                                                            )}
                                                        </div>
                                                    ) : null,
                                                )}

                                                <div className={cn('flex items-center gap-1 text-[10px] text-muted-foreground', mio && 'justify-end')}>
                                                    {mio &&
                                                        (m.sent_by === 'human' ? (
                                                            <>
                                                                <User className="size-2.5" /> Tú
                                                            </>
                                                        ) : (
                                                            <>
                                                                <Bot className="size-2.5" /> Lore
                                                            </>
                                                        ))}
                                                    <span>{hora(m.created_at)}</span>
                                                </div>
                                            </div>
                                        </motion.div>
                                    );
                                })}
                            </div>

                            {/* ── Responder ──────────────────────────── */}
                            <footer className="border-t border-border/60 px-5 py-3">
                                {flash?.error && (
                                    <div className="mb-2 rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs text-destructive">
                                        {flash.error}
                                    </div>
                                )}

                                {!selected.window_open && (
                                    <div className="mb-2 flex items-start gap-2 rounded-lg bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-300">
                                        <Clock className="mt-0.5 size-3.5 shrink-0" />
                                        <span>
                                            Pasaron más de 24 horas desde su último mensaje. WhatsApp no permite escribirle hasta que ella vuelva a
                                            escribir. Si es urgente, llámala por teléfono.
                                        </span>
                                    </div>
                                )}

                                {data.archivo && (
                                    <div className="mb-2 flex items-center gap-2 rounded-lg border border-border/60 bg-background/60 px-3 py-2 text-xs">
                                        {data.archivo.type.startsWith('video/') ? (
                                            <FileText className="size-3.5 shrink-0 text-muted-foreground" />
                                        ) : (
                                            <img
                                                src={URL.createObjectURL(data.archivo)}
                                                alt=""
                                                className="size-9 shrink-0 rounded object-cover"
                                            />
                                        )}
                                        <span className="flex-1 truncate">{data.archivo.name}</span>
                                        <button type="button" onClick={quitarArchivo} className="text-muted-foreground hover:text-foreground">
                                            <X className="size-4" />
                                        </button>
                                    </div>
                                )}

                                <form onSubmit={enviar} className="flex items-end gap-2">
                                    <input
                                        ref={archivoRef}
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp,video/mp4,video/3gpp"
                                        className="hidden"
                                        onChange={(e) => setData('archivo', e.target.files?.[0] ?? null)}
                                    />
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="outline"
                                        disabled={!puedeEscribir || processing}
                                        onClick={() => archivoRef.current?.click()}
                                        title="Adjuntar imagen o video"
                                    >
                                        <Paperclip className="size-4" />
                                    </Button>
                                    <textarea
                                        value={data.content}
                                        onChange={(e) => setData('content', e.target.value)}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter' && !e.shiftKey) {
                                                e.preventDefault();
                                                enviar(e);
                                            }
                                        }}
                                        rows={1}
                                        disabled={!puedeEscribir || processing}
                                        placeholder={
                                            puedeEscribir
                                                ? data.archivo
                                                    ? 'Agrega un texto para la imagen (opcional)…'
                                                    : 'Escribe tu respuesta… (Enter para enviar)'
                                                : 'No se le puede escribir en este momento'
                                        }
                                        className="max-h-32 flex-1 resize-none rounded-xl border border-border/60 bg-background/60 px-4 py-2.5 text-sm outline-none focus:border-primary/40 disabled:opacity-50"
                                    />
                                    <Button
                                        type="submit"
                                        size="icon"
                                        disabled={!puedeEscribir || processing || (!data.content.trim() && !data.archivo)}
                                    >
                                        <SendHorizonal className="size-4" />
                                    </Button>
                                </form>

                                {selected.bot_enabled && puedeEscribir && (
                                    <p className="mt-1.5 text-[11px] text-muted-foreground">
                                        Si escribes, Lore se pausa automáticamente en este chat para no responder encima.
                                    </p>
                                )}
                            </footer>
                        </>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
