<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Services\BotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BotController extends Controller
{
    public function playground(Request $request): Response
    {
        $conversation = $request->user()->conversations()
            ->where('channel', 'panel')
            ->latest()
            ->first();

        $bot = BotService::fromUser($request->user());

        return Inertia::render('bot/playground', [
            'conversationId' => $conversation?->id,
            'messages' => $conversation
                ? $conversation->messages()->orderBy('id')->get(['id', 'role', 'content', 'media'])
                : [],
            'ready' => $bot->isReady(),
            'canSchedule' => $bot->canSchedule(),
            'knowledgeCount' => $request->user()->knowledgeEntries()->where('is_active', true)->count(),
            'servicesCount' => $request->user()->services()->where('is_active', true)->count(),
            'campaigns' => $request->user()->campaigns()
                ->where('is_active', true)
                ->with('service:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'offer', 'service_id']),
        ]);
    }

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer'],
            'campaign_id' => ['nullable', 'integer'],
        ]);

        $conversation = $this->resolveConversation($request, $data['conversation_id'] ?? null);

        $conversation->messages()->create(['role' => 'user', 'content' => $data['message']]);

        // Campaña de Meta seleccionada (opcional): da contexto de origen al bot.
        $campaign = filled($data['campaign_id'] ?? null)
            ? $request->user()->campaigns()->whereKey($data['campaign_id'])->first()
            : null;

        $bot = BotService::fromUser($request->user());

        if (! $bot->isReady()) {
            return response()->json([
                'conversation_id' => $conversation->id,
                'ready' => false,
                'reply' => 'La IA todavía no está activa. Pega tu API key de Anthropic en Configuración → Integración IA y podré responder con el conocimiento de tu clínica.',
            ]);
        }

        try {
            $result = $bot->reply($conversation, $campaign);
        } catch (\Throwable $e) {
            return response()->json([
                'conversation_id' => $conversation->id,
                'ready' => true,
                'error' => true,
                'reply' => 'Ocurrió un error al contactar a Claude: ' . $e->getMessage(),
            ], 200);
        }

        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $result['text'],
            'media' => $result['media'] ?: null,
        ]);

        return response()->json([
            'conversation_id' => $conversation->id,
            'ready' => true,
            'reply' => $result['text'],
            'media' => $result['media'],
            'message_id' => $message->id,
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->user()->conversations()->create(['channel' => 'panel', 'title' => 'Prueba del asistente']);

        return back();
    }

    private function resolveConversation(Request $request, ?int $id): Conversation
    {
        if ($id) {
            $existing = $request->user()->conversations()->whereKey($id)->first();
            if ($existing) {
                return $existing;
            }
        }

        return $request->user()->conversations()->create(['channel' => 'panel', 'title' => 'Prueba del asistente']);
    }
}
