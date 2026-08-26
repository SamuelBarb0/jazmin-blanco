import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Bot, ChevronLeft, Clock, FileText, HandHelping, MessageCircle, Paperclip, Pause, Play, Search, SendHorizonal, User, X } from 'lucide-react';
import { motion } from 'motion/react';
import { useEffect, useRef, useState } from 'react';

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
    needs_human: boolean;
    last_message_at: string | null;
    last_message_id: number | null;
    preview: string | null;
    sin_responder: boolean;
}

interface Selected {
    id: number;
    title: string;
    channel: string;
    bot_enabled: boolean;
    bot_paused_at: string | null;
    needs_human: boolean;
    escalated_at: string | null;
    escalation_reason: string | null;
    lead: LeadRef | null;
    window_open: boolean;
    window_closes_at: string | null;
    messages: Msg[];
    older_count: number;
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

export default function Inbox({
    conversations,
    selected,
    q = '',
    total = 0,
    auto_selected: autoSelected = false,
}: {
    conversations: ConversationRow[];
    selected: Selected | null;
    q?: string;
    total?: number;
    auto_selected?: boolean;
}) {
    // El chat que abre solo el escritorio para no dejar el panel vacío NO debe
    // adueñarse de la pantalla en celular: ahí se entra por la lista. Se resuelve
    // por CSS y no con `useIsMobile()` a propósito — ese hook devuelve `undefined`
    // en el primer render y el chat asomaría un instante antes de esconderse.
    const listaEnMovil = !selected || autoSelected;
    const { flash } = usePage<SharedData>().props;
    const scrollRef = useRef<HTMLDivElement>(null);
    const archivoRef = useRef<HTMLInputElement>(null);
    const { data, setData, post, processing, reset } = useForm<{ content: string; archivo: File | null }>({
        content: '',
        archivo: null,
    });

    const esperandoHumano = conversations.filter((c) => c.needs_human).length;
    const sinResponder = conversations.filter((c) => c.sin_responder && !c.needs_human).length;

    // La búsqueda va al servidor —hace falta para mirar dentro de los
    // mensajes—, pero no en cada tecla: se espera a que deje de escribir. El
    // valor que se ve es el local, así que el campo nunca "salta" cuando llega
    // la respuesta ni cuando el refresco de cada 5 s recarga la lista.
    const [busqueda, setBusqueda] = useState(q);

    useEffect(() => {
        if (busqueda === q) return;

        const id = setTimeout(() => {
            router.get(
                route('inbox.index'),
                busqueda.trim() ? { q: busqueda.trim() } : {},
                { preserveState: true, preserveScroll: true, replace: true, only: ['conversations', 'q', 'total', 'selected', 'auto_selected'] },
            );
        }, 350);

        return () => clearTimeout(id);
    }, [busqueda, q]);

    useEffect(() => {
        scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight });
    }, [selected?.id, selected?.messages.length]);

    // La bandeja se refresca sola: no hay tiempo real (ni websockets), pero una
    // paciente que escribe aparece en unos segundos sin tocar nada.
    //
    // Cinco segundos y no veinte porque la doctora atiende desde el celular y
    // veinte se sienten como "no funciona". El coste es bajo: la recarga pide
    // solo estas dos props, no la página entera.
    //
    // OJO: esto es la MITAD de la espera. La otra mitad es la cola —el mensaje
    // no existe en la base hasta que el cron levanta el worker—, así que bajar
    // esto sin tocar el cron no se nota apenas.
    useEffect(() => {
        // Solo se pide la LISTA, que pesa poco. El chat abierto se recarga
        // aparte y únicamente si cambió (ver el efecto de abajo): reenviarlo
        // entero cada 5 segundos era el grueso del tráfico, y desde el celular
        // de la doctora eso se paga en datos.
        const refrescar = () => {
            if (document.visibilityState !== 'visible') return;
            router.reload({ only: ['conversations'] });
        };

        const id = setInterval(refrescar, 5000);
        // Al volver a la pestaña se refresca ya, sin esperar al siguiente ciclo.
        document.addEventListener('visibilitychange', refrescar);

        return () => {
            clearInterval(id);
            document.removeEventListener('visibilitychange', refrescar);
        };
    }, []);

    // Trae el chat abierto solo cuando la lista delata un mensaje nuevo.
    // Se compara por id (entero) y no por fecha: un desajuste de formato o de
    // zona horaria dejaría la condición siempre en verdadero y la bandeja se
    // recargaría en bucle.
    const recargando = useRef(false);
    useEffect(() => {
        if (!selected || recargando.current) return;

        const fila = conversations.find((c) => c.id === selected.id);
        const ultimoCargado = selected.messages.at(-1)?.id ?? null;

        if (!fila?.last_message_id || fila.last_message_id === ultimoCargado) return;

        recargando.current = true;
        router.reload({
            only: ['selected'],
            onFinish: () => {
                recargando.current = false;
            },
        });
    }, [conversations, selected]);

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

            {/* En celular no caben las dos columnas: se ve la lista, y al abrir
                un chat se ve solo el chat con un botón para volver. En pantalla
                grande se mantienen lado a lado. `dvh` en vez de `vh` porque en
                el navegador del móvil la barra de direcciones se esconde y con
                `vh` el compositor queda fuera de la pantalla. */}
            {/* `flex-1 min-h-0` en vez de restarle una altura fija al viewport:
                el `calc(100dvh-9rem)` dejaba 80px muertos abajo porque 9rem no
                era la altura real de la cabecera. Así ocupa exactamente lo que
                sobra, y `min-h-0` es lo que permite que el chat haga scroll
                dentro en vez de estirar la página. */}
            <div className="flex min-h-0 flex-1 gap-4 p-2 md:p-4">
                {/* ── Lista de chats ─────────────────────────────── */}
                <aside
                    className={cn(
                        'glass w-full shrink-0 flex-col overflow-hidden rounded-xl md:flex md:w-80',
                        listaEnMovil ? 'flex' : 'hidden',
                    )}
                >
                    <header className="border-b border-border/60 px-4 py-3">
                        <h2 className="font-display text-lg">Conversaciones</h2>
                        <p className="text-xs text-muted-foreground">
                            {q ? `${conversations.length} de ${total} chats` : `${conversations.length} chats de WhatsApp`}
                        </p>

                        <div className="relative mt-2">
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
                            <input
                                type="search"
                                value={busqueda}
                                onChange={(e) => setBusqueda(e.target.value)}
                                placeholder="Buscar por nombre, número o mensaje…"
                                // Se oculta la «x» propia del navegador: con la nuestra al lado salían dos.
                                className="w-full rounded-lg border border-border/60 bg-background/60 py-1.5 pr-7 pl-8 text-xs outline-none focus:border-primary/40 [&::-webkit-search-cancel-button]:hidden"
                            />
                            {busqueda !== '' && (
                                <button
                                    type="button"
                                    onClick={() => setBusqueda('')}
                                    className="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                    aria-label="Limpiar la búsqueda"
                                >
                                    <X className="size-3.5" />
                                </button>
                            )}
                        </div>
                        {/* El contador va en la cabecera y no solo como etiqueta
                            en cada fila: un chat escalado en la posición 30 de la
                            lista no se ve, y la gracia del escalamiento es
                            justamente que alguien se entere rápido. */}
                        {esperandoHumano > 0 && (
                            <p className="mt-1.5 inline-flex items-center gap-1.5 rounded-full bg-rose-500/15 px-2 py-0.5 text-[11px] font-medium text-rose-600 dark:text-rose-400">
                                <HandHelping className="size-3" />
                                {esperandoHumano === 1 ? '1 chat espera a una persona' : `${esperandoHumano} chats esperan a una persona`}
                            </p>
                        )}
                        {/* Un chat con Lore en pausa y la paciente esperando no
                            se distingue de uno cualquiera al bajar la lista: así
                            es como varias pacientes se quedaron días sin
                            respuesta. El contador lo pone donde se ve. */}
                        {sinResponder > 0 && (
                            <p className="mt-1.5 ml-1.5 inline-flex items-center gap-1.5 rounded-full bg-amber-500/15 px-2 py-0.5 text-[11px] font-medium text-amber-600 dark:text-amber-400">
                                <AlertTriangle className="size-3" />
                                {sinResponder === 1 ? '1 paciente sin responder' : `${sinResponder} pacientes sin responder`}
                            </p>
                        )}
                    </header>

                    <div className="flex-1 overflow-y-auto">
                        {conversations.length === 0 && (
                            <p className="p-4 text-sm text-muted-foreground">
                                {q
                                    ? `Ningún chat coincide con «${q}». Se busca por nombre, por número (bastan los últimos dígitos) y dentro de los mensajes.`
                                    : 'Todavía no hay conversaciones. Aparecerán aquí en cuanto una paciente escriba por WhatsApp.'}
                            </p>
                        )}

                        {conversations.map((c) => (
                            <button
                                key={c.id}
                                // La búsqueda sobrevive al abrir un chat: si no,
                                // volver a la lista devolvía los 300 y había que
                                // teclear el número otra vez.
                                onClick={() => router.get(route('inbox.show', c.id), q ? { q } : {}, { preserveState: true })}
                                className={cn(
                                    'flex w-full flex-col gap-1 border-b border-border/40 px-4 py-3 text-left transition hover:bg-muted/50',
                                    selected?.id === c.id && 'bg-muted',
                                )}
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <span className="truncate text-sm font-medium">{c.lead?.name || c.title}</span>
                                    <span className="shrink-0 text-[11px] text-muted-foreground">{hace(c.last_message_at)}</span>
                                </div>
                                {/* El número, a la vista: es por lo que la
                                    doctora identifica a una paciente cuando el
                                    nombre viene del perfil de WhatsApp y no
                                    coincide con el de la historia. */}
                                {c.lead?.phone && <span className="truncate text-[11px] text-muted-foreground/80">{c.lead.phone}</span>}
                                <span className="truncate text-xs text-muted-foreground">{c.preview || 'Sin mensajes'}</span>
                                {/* Escalado y en pausa no son lo mismo: uno es una
                                    alerta por atender, el otro una decisión de la
                                    doctora. Si están los dos, manda la alerta. */}
                                {c.needs_human ? (
                                    <span className="inline-flex w-fit items-center gap-1 rounded-full bg-rose-500/15 px-2 py-0.5 text-[10px] font-medium text-rose-600 dark:text-rose-400">
                                        <HandHelping className="size-2.5" /> Espera a una persona
                                    </span>
                                ) : (
                                    !c.bot_enabled &&
                                    (c.sin_responder ? (
                                        <span className="inline-flex w-fit items-center gap-1 rounded-full bg-amber-500/25 px-2 py-0.5 text-[10px] font-semibold text-amber-700 dark:text-amber-300">
                                            <AlertTriangle className="size-2.5" /> Escribió y nadie respondió
                                        </span>
                                    ) : (
                                        <span className="inline-flex w-fit items-center gap-1 rounded-full bg-amber-500/15 px-2 py-0.5 text-[10px] font-medium text-amber-600 dark:text-amber-400">
                                            <Pause className="size-2.5" /> Lore en pausa
                                        </span>
                                    ))
                                )}
                            </button>
                        ))}
                    </div>
                </aside>

                {/* ── Chat ───────────────────────────────────────── */}
                <section
                    className={cn(
                        'glass flex-1 flex-col overflow-hidden rounded-xl md:flex',
                        selected && !listaEnMovil ? 'flex' : 'hidden',
                    )}
                >
                    {!selected && (
                        <div className="flex flex-1 items-center justify-center text-sm text-muted-foreground">
                            <MessageCircle className="mr-2 size-4" /> Elige una conversación
                        </div>
                    )}

                    {selected && (
                        <>
                            <header className="flex items-center justify-between gap-3 border-b border-border/60 px-3 py-3 md:px-5">
                                {/* Volver a la lista: solo hace falta en celular,
                                    donde la lista está oculta. */}
                                <button
                                    type="button"
                                    onClick={() => router.get(route('inbox.index'), q ? { lista: 1, q } : { lista: 1 }, { preserveState: true })}
                                    className="-ml-1 shrink-0 rounded-lg p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground md:hidden"
                                    aria-label="Volver a las conversaciones"
                                >
                                    <ChevronLeft className="size-5" />
                                </button>

                                <div className="min-w-0 flex-1">
                                    <h2 className="truncate font-display text-lg">{selected.lead?.name || selected.title}</h2>
                                    <p className="text-xs text-muted-foreground">{selected.lead?.phone || 'Sin teléfono'}</p>
                                </div>

                                {/* En celular el botón se queda en el icono: el
                                    texto completo empujaba el nombre fuera. */}
                                <Button
                                    variant={selected.bot_enabled ? 'outline' : 'default'}
                                    size="sm"
                                    className="shrink-0"
                                    onClick={() => router.patch(route('inbox.toggle', selected.id), {}, { preserveScroll: true })}
                                >
                                    {selected.bot_enabled ? (
                                        <>
                                            <Pause className="size-4" />
                                            <span className="hidden sm:inline">Pausar a Lore</span>
                                        </>
                                    ) : (
                                        <>
                                            <Play className="size-4" />
                                            <span className="hidden sm:inline">Reactivar a Lore</span>
                                        </>
                                    )}
                                </Button>
                            </header>

                            {selected.needs_human ? (
                                <div className="flex items-start gap-2 border-b border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-700 md:px-5 dark:text-rose-300">
                                    <HandHelping className="mt-0.5 size-3.5 shrink-0" />
                                    <span>
                                        <strong className="font-medium">Lore pidió que atendieras este chat</strong>
                                        {selected.escalation_reason && <> — {selected.escalation_reason}</>}
                                        <br />
                                        Ya dejó de responder aquí. La alerta se apaga cuando le escribas o reactives a Lore.
                                    </span>
                                </div>
                            ) : (
                                !selected.bot_enabled && (
                                    <div className="flex items-center gap-2 border-b border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-700 md:px-5 dark:text-amber-300">
                                        <AlertTriangle className="size-3.5 shrink-0" />
                                        Lore no está respondiendo en este chat. Las pacientes solo reciben lo que escribas tú.
                                    </div>
                                )
                            )}

                            <div ref={scrollRef} className="flex-1 space-y-3 overflow-y-auto px-3 py-4 md:px-5">
                                {selected.older_count > 0 && (
                                    <p className="text-center text-[11px] text-muted-foreground">
                                        Hay {selected.older_count} mensaje{selected.older_count === 1 ? '' : 's'} anterior
                                        {selected.older_count === 1 ? '' : 'es'} en esta conversación.
                                    </p>
                                )}
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
                            <footer className="border-t border-border/60 px-3 py-3 md:px-5">
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
                                                : 'No se puede escribir ahora'
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
