<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Services\WhatsAppService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bandeja de conversaciones de WhatsApp.
 *
 * Le da a la doctora lo que antes no existía: ver los chats reales, pausar al
 * asistente en uno concreto y responder ella misma por el número del
 * consultorio. Mientras un chat está en pausa, el asistente guarda los mensajes
 * entrantes pero no contesta (ver ProcessWhatsAppMessage).
 */
class InboxController extends Controller
{
    public function index(Request $request, ?Conversation $conversation = null): Response
    {
        $user = $request->user();

        $conversations = $user->conversations()
            ->where('channel', '!=', 'panel')
            ->with(['lead:id,name,phone'])
            ->withMax('messages as last_message_at', 'created_at')
            ->orderByDesc('last_message_at')
            ->get()
            ->map(fn (Conversation $c) => [
                'id' => $c->id,
                'title' => $c->title ?: ($c->lead?->name ?: 'Sin nombre'),
                'lead' => $c->lead ? ['id' => $c->lead->id, 'name' => $c->lead->name, 'phone' => $c->lead->phone] : null,
                'channel' => $c->channel,
                'bot_enabled' => $c->bot_enabled,
                'last_message_at' => $c->last_message_at,
                'preview' => $c->messages()->latest('id')->value('content'),
            ]);

        // Por defecto se abre la conversación más reciente.
        if ($conversation?->exists && $conversation->user_id !== $user->id) {
            abort(403);
        }
        $selected = $conversation?->exists
            ? $conversation
            : $user->conversations()->where('channel', '!=', 'panel')
                ->withMax('messages as last_message_at', 'created_at')
                ->orderByDesc('last_message_at')
                ->first();

        return Inertia::render('inbox/index', [
            'conversations' => $conversations,
            'selected' => $selected ? $this->serialize($selected) : null,
        ]);
    }

    /** Pausa o reanuda al asistente en una conversación. */
    public function toggleBot(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorizeConversation($request, $conversation);

        $activar = ! $conversation->bot_enabled;

        $conversation->forceFill([
            'bot_enabled' => $activar,
            'bot_paused_at' => $activar ? null : now(),
        ])->save();

        return back()->with('success', $activar
            ? 'El asistente vuelve a responder en este chat.'
            : 'Asistente pausado. A partir de ahora respondes tú en este chat.');
    }

    /** Envía un mensaje escrito por la doctora al número de la paciente. */
    public function send(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorizeConversation($request, $conversation);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:4000'],
        ]);

        $telefono = $conversation->lead?->phone;
        if (blank($telefono)) {
            return back()->with('error', 'Esta paciente no tiene teléfono registrado, no se le puede escribir.');
        }

        if (! $conversation->windowIsOpen()) {
            return back()->with('error', 'Pasaron más de 24 horas desde el último mensaje de la paciente. WhatsApp no permite escribirle texto libre hasta que ella vuelva a escribir.');
        }

        $enviado = WhatsAppService::fromConfig()->sendText($telefono, $data['content']);

        if (! $enviado) {
            return back()->with('error', 'No se pudo enviar el mensaje por WhatsApp. Revisa la conexión del número.');
        }

        $conversation->messages()->create([
            // Se guarda como 'assistant' para que, al reactivar al asistente,
            // este entienda que ese mensaje salió de nuestro lado.
            'role' => 'assistant',
            'sent_by' => 'human',
            'content' => $data['content'],
        ]);

        // Escribir a mano implica tomar el control: si el asistente seguía
        // activo, se pausa solo para que no responda encima.
        if ($conversation->bot_enabled) {
            $conversation->forceFill(['bot_enabled' => false, 'bot_paused_at' => now()])->save();
        }

        return back();
    }

    /**
     * @return array<string,mixed>
     */
    private function serialize(Conversation $conversation): array
    {
        $conversation->load('lead:id,name,phone');
        $ultimoEntrante = $conversation->lastInboundAt();

        return [
            'id' => $conversation->id,
            'title' => $conversation->title ?: ($conversation->lead?->name ?: 'Sin nombre'),
            'channel' => $conversation->channel,
            'bot_enabled' => $conversation->bot_enabled,
            'bot_paused_at' => $conversation->bot_paused_at?->toIso8601String(),
            'lead' => $conversation->lead ? [
                'id' => $conversation->lead->id,
                'name' => $conversation->lead->name,
                'phone' => $conversation->lead->phone,
            ] : null,
            'window_open' => $conversation->windowIsOpen(),
            'window_closes_at' => $ultimoEntrante?->copy()->addDay()->toIso8601String(),
            'messages' => $conversation->messages()->orderBy('id')->get()
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'role' => $m->role,
                    'sent_by' => $m->sent_by,
                    'content' => $m->content,
                    'media' => $m->media,
                    'created_at' => $m->created_at?->toIso8601String(),
                ]),
        ];
    }

    private function authorizeConversation(Request $request, Conversation $conversation): void
    {
        abort_unless($conversation->user_id === $request->user()->id, 403);
    }
}
