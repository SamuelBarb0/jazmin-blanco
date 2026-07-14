import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { AnimatePresence, motion } from 'motion/react';
import { BookOpen, Bot, CalendarCheck, Megaphone, RotateCcw, SendHorizonal, Sparkles } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface ChatMedia {
    type: 'image' | 'video';
    url: string | null;
    caption?: string;
    service?: string;
}

interface Msg {
    id?: number;
    role: 'user' | 'assistant';
    content: string;
    media?: ChatMedia[] | null;
}

interface CampaignOpt {
    id: number;
    name: string;
    offer: string | null;
    service_id: number | null;
    service?: { id: number; name: string } | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Asistente', href: '/asistente' }];

const suggestions = ['¿Dónde están ubicados?', '¿Qué incluye la valoración?', 'Quiero agendar una cita', '¿Cuánto dura la recuperación?'];

function getCookie(name: string): string {
    const match = document.cookie.match(new RegExp('(^|;\\s*)' + name + '=([^;]*)'));
    return match ? decodeURIComponent(match[2]) : '';
}

export default function BotPlayground({
    conversationId: initialId,
    messages: initialMessages,
    ready,
    canSchedule,
    knowledgeCount,
    servicesCount,
    campaigns,
}: {
    conversationId: number | null;
    messages: Msg[];
    ready: boolean;
    canSchedule: boolean;
    knowledgeCount: number;
    servicesCount: number;
    campaigns: CampaignOpt[];
}) {
    const [messages, setMessages] = useState<Msg[]>(initialMessages);
    const [conversationId, setConversationId] = useState<number | null>(initialId);
    const [input, setInput] = useState('');
    const [sending, setSending] = useState(false);
    const [campaignId, setCampaignId] = useState<number | null>(null);
    const selectedCampaign = campaigns.find((c) => c.id === campaignId) ?? null;
    const scrollRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' });
    }, [messages, sending]);

    const send = async (text: string) => {
        const content = text.trim();
        if (!content || sending) return;
        setInput('');
        setMessages((m) => [...m, { role: 'user', content }]);
        setSending(true);

        try {
            const res = await fetch(route('bot.chat'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': getCookie('XSRF-TOKEN'),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ message: content, conversation_id: conversationId, campaign_id: campaignId }),
            });
            const data = await res.json();
            setConversationId(data.conversation_id ?? conversationId);
            setMessages((m) => [...m, { role: 'assistant', content: data.reply ?? 'Sin respuesta.', media: data.media ?? null }]);
        } catch {
            setMessages((m) => [...m, { role: 'assistant', content: 'No pude conectar con el servidor. Intenta de nuevo.' }]);
        } finally {
            setSending(false);
        }
    };

    const resetChat = () => {
        router.post(route('bot.reset'), {}, { preserveScroll: true, onSuccess: () => { setMessages([]); setConversationId(null); } });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Asistente" />
            <div className="mx-auto flex h-[calc(100dvh-8rem)] w-full max-w-3xl flex-col gap-4 p-4">
                {/* Encabezado */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <span className="from-primary to-chart-3 text-primary-foreground flex size-11 items-center justify-center rounded-xl bg-gradient-to-br shadow-lg shadow-primary/20">
                            <Bot className="size-6" />
                        </span>
                        <div>
                            <h1 className="font-display text-2xl tracking-tight leading-none">Asistente de la clínica</h1>
                            <div className="text-muted-foreground mt-1 flex items-center gap-2 text-xs">
                                <span className={cn('inline-flex items-center gap-1', ready ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600')}>
                                    <span className={cn('size-1.5 rounded-full', ready ? 'bg-emerald-500' : 'bg-amber-500')} />
                                    {ready ? 'IA activa' : 'IA inactiva'}
                                </span>
                                <span>·</span>
                                <span className="inline-flex items-center gap-1"><BookOpen className="size-3" /> {knowledgeCount + servicesCount} fuentes</span>
                                {canSchedule && (
                                    <>
                                        <span>·</span>
                                        <span className="inline-flex items-center gap-1 text-sky-600 dark:text-sky-400">
                                            <CalendarCheck className="size-3" /> Puede agendar
                                        </span>
                                    </>
                                )}
                            </div>
                        </div>
                    </div>
                    <Button variant="outline" size="sm" onClick={resetChat}>
                        <RotateCcw className="h-4 w-4" /> Reiniciar
                    </Button>
                </div>

                {!ready && (
                    <div className="rounded-xl border border-amber-300/60 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                        El bot ya conoce tu clínica ({knowledgeCount + servicesCount} fuentes), pero necesita la API key para responder. Actívala en{' '}
                        <Link href={route('ai.edit')} className="font-medium underline">Configuración → Integración IA</Link>. Mientras tanto puedes escribir para ver el flujo.
                    </div>
                )}

                {/* Conversación */}
                <div ref={scrollRef} className="glass flex-1 overflow-y-auto rounded-2xl p-4">
                    {messages.length === 0 ? (
                        <div className="flex h-full flex-col items-center justify-center gap-4 text-center">
                            <span className="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-2xl">
                                <Sparkles className="size-7" />
                            </span>
                            <div>
                                <p className="font-medium">Prueba al asistente</p>
                                <p className="text-muted-foreground text-sm">Escríbele como lo haría un paciente por WhatsApp.</p>
                            </div>
                            <div className="flex flex-wrap justify-center gap-2">
                                {suggestions.map((s) => (
                                    <button key={s} onClick={() => send(s)} className="border-border/60 hover:bg-muted/50 rounded-full border px-3 py-1.5 text-sm transition">
                                        {s}
                                    </button>
                                ))}
                            </div>
                        </div>
                    ) : (
                        <div className="flex flex-col gap-3">
                            <AnimatePresence initial={false}>
                                {messages.map((m, i) => (
                                    <motion.div
                                        key={i}
                                        initial={{ opacity: 0, y: 10 }}
                                        animate={{ opacity: 1, y: 0 }}
                                        transition={{ duration: 0.3 }}
                                        className={cn('flex gap-2.5', m.role === 'user' ? 'justify-end' : 'justify-start')}
                                    >
                                        {m.role === 'assistant' && (
                                            <span className="bg-primary/10 text-primary mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg">
                                                <Bot className="size-4" />
                                            </span>
                                        )}
                                        <div className={cn('flex max-w-[78%] flex-col gap-2', m.role === 'user' ? 'items-end' : 'items-start')}>
                                            {m.content && (
                                                <div
                                                    className={cn(
                                                        'rounded-2xl px-4 py-2.5 text-sm whitespace-pre-wrap',
                                                        m.role === 'user' ? 'bg-primary text-primary-foreground rounded-br-md' : 'liquid-glass rounded-bl-md',
                                                    )}
                                                >
                                                    {m.content}
                                                </div>
                                            )}
                                            {m.media?.map((media, mi) =>
                                                media.url ? (
                                                    <figure key={mi} className="overflow-hidden rounded-2xl rounded-bl-md border border-border/50">
                                                        {media.type === 'image' ? (
                                                            <img src={media.url} alt={media.caption ?? ''} className="max-h-72 w-full object-cover" />
                                                        ) : (
                                                            <video src={media.url} className="max-h-72 w-full bg-black" controls preload="metadata" />
                                                        )}
                                                        {media.caption && (
                                                            <figcaption className="bg-background/60 text-muted-foreground px-3 py-1.5 text-xs">
                                                                {media.caption}
                                                            </figcaption>
                                                        )}
                                                    </figure>
                                                ) : null,
                                            )}
                                        </div>
                                    </motion.div>
                                ))}
                            </AnimatePresence>
                            {sending && (
                                <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="flex gap-2.5">
                                    <span className="bg-primary/10 text-primary mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg">
                                        <Bot className="size-4" />
                                    </span>
                                    <div className="liquid-glass flex items-center gap-1 rounded-2xl rounded-bl-md px-4 py-3.5">
                                        <Dot delay={0} />
                                        <Dot delay={0.15} />
                                        <Dot delay={0.3} />
                                    </div>
                                </motion.div>
                            )}
                        </div>
                    )}
                </div>

                {/* Simular campaña de origen (Meta) */}
                {campaigns.length > 0 && (
                    <div className="flex flex-wrap items-center gap-2 px-1 text-xs">
                        <span className="text-muted-foreground inline-flex items-center gap-1.5">
                            <Megaphone className="size-3.5" /> Simular paciente de:
                        </span>
                        <select
                            value={campaignId ?? ''}
                            onChange={(e) => setCampaignId(e.target.value ? Number(e.target.value) : null)}
                            className="border-input bg-background h-8 rounded-md border px-2 text-xs focus-visible:ring-2 focus-visible:outline-hidden"
                        >
                            <option value="">Sin campaña (atención general)</option>
                            {campaigns.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                    {c.service ? ` · ${c.service.name}` : ''}
                                </option>
                            ))}
                        </select>
                        {selectedCampaign?.offer && <span className="text-muted-foreground/80 truncate italic">“{selectedCampaign.offer}”</span>}
                    </div>
                )}

                {/* Entrada */}
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        send(input);
                    }}
                    className="glass flex items-end gap-2 rounded-2xl p-2"
                >
                    <textarea
                        value={input}
                        onChange={(e) => setInput(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && !e.shiftKey) {
                                e.preventDefault();
                                send(input);
                            }
                        }}
                        rows={1}
                        placeholder="Escribe un mensaje…"
                        className="max-h-32 flex-1 resize-none bg-transparent px-3 py-2 text-sm focus-visible:outline-hidden"
                    />
                    <Button type="submit" size="icon" disabled={sending || !input.trim()} className="shrink-0">
                        <SendHorizonal className="size-4" />
                    </Button>
                </form>
            </div>
        </AppLayout>
    );
}

function Dot({ delay }: { delay: number }) {
    return (
        <motion.span
            className="bg-muted-foreground/60 size-1.5 rounded-full"
            animate={{ y: [0, -4, 0] }}
            transition={{ duration: 0.7, repeat: Infinity, delay }}
        />
    );
}
