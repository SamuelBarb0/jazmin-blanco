<?php

namespace App\Services;

use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicService
{
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly string $model = 'claude-opus-4-8',
        private readonly string $baseUrl = 'https://api.anthropic.com/v1',
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            apiKey: Settings::anthropicKey(),
            model: Settings::anthropicModel(),
            baseUrl: rtrim(config('services.anthropic.base_url', 'https://api.anthropic.com/v1'), '/'),
        );
    }

    /**
     * Verifica que la API key responda con una petición mínima.
     */
    public function ping(): bool
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('No hay una API key configurada.');
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(30)->post("{$this->baseUrl}/messages", [
            'model' => $this->model,
            'max_tokens' => 8,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ]);

        if ($response->failed()) {
            $message = $response->json('error.message') ?? 'La API rechazó la clave.';

            throw new RuntimeException($message);
        }

        return true;
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey);
    }

    /**
     * Genera un texto de contexto/descripción profesional para un servicio
     * estético a partir de los datos básicos que la doctora introduce.
     *
     * @param  array{name?:string,category?:string,short_description?:string,price?:float|string|null,duration_minutes?:int|null,notes?:string}  $service
     */
    public function generateServiceContext(array $service): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('La clave de API de Anthropic no está configurada. Agrega ANTHROPIC_API_KEY en el archivo .env.');
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(60)->post("{$this->baseUrl}/messages", [
            'model' => $this->model,
            'max_tokens' => 1200,
            'system' => $this->systemPrompt(),
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->buildUserPrompt($service),
                ],
            ],
        ]);

        if ($response->failed()) {
            $message = $response->json('error.message') ?? $response->body();

            throw new RuntimeException("Error al contactar la API de Anthropic: {$message}");
        }

        // La respuesta es una lista de bloques; tomamos el texto.
        $text = collect($response->json('content', []))
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n");

        return trim($text);
    }

    /**
     * Conversación genérica con un system prompt y un historial de mensajes.
     *
     * @param  array<int,array{role:string,content:string}>  $messages
     */
    public function chat(string $system, array $messages, int $maxTokens = 1024): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('La API key de Anthropic no está configurada.');
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(90)->post("{$this->baseUrl}/messages", [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => $messages,
        ]);

        if ($response->failed()) {
            $message = $response->json('error.message') ?? $response->body();

            throw new RuntimeException("Error al contactar la API de Anthropic: {$message}");
        }

        return trim(collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode("\n"));
    }

    /**
     * Conversación con soporte de herramientas (tool use). Devuelve la respuesta
     * completa de la API (bloques de contenido + stop_reason) para que el llamador
     * maneje el ciclo de ejecución de herramientas.
     *
     * @param  array<int,array{role:string,content:mixed}>  $messages
     * @param  array<int,array<string,mixed>>  $tools
     * @return array<string,mixed>
     */
    public function rawChat(string $system, array $messages, array $tools = [], int $maxTokens = 1024): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('La API key de Anthropic no está configurada.');
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => $messages,
        ];

        if (! empty($tools)) {
            $payload['tools'] = $tools;
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(120)->post("{$this->baseUrl}/messages", $payload);

        if ($response->failed()) {
            $message = $response->json('error.message') ?? $response->body();

            throw new RuntimeException("Error al contactar la API de Anthropic: {$message}");
        }

        return $response->json();
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        Eres un redactor experto en marketing para clínicas y consultorios de medicina estética.
        Tu tarea es redactar el contexto/descripción de un servicio estético para la Dra. Jazmin Blanco.

        Reglas:
        - Escribe en español, con un tono profesional, cálido y confiable.
        - Estructura la respuesta con: un párrafo de presentación, una lista breve de beneficios,
          a quién está dirigido y, si aplica, una nota sobre cuidados o expectativas realistas.
        - No inventes precios, promociones ni resultados médicos garantizados.
        - No uses promesas exageradas ni lenguaje sensacionalista.
        - Devuelve solo el texto del contexto, sin encabezados de tipo "Aquí tienes" ni comentarios.
        PROMPT;
    }

    /**
     * @param  array<string,mixed>  $service
     */
    private function buildUserPrompt(array $service): string
    {
        $lines = ['Genera el contexto para este servicio estético con los siguientes datos:'];

        $fields = [
            'Nombre del servicio' => $service['name'] ?? null,
            'Categoría' => $service['category'] ?? null,
            'Descripción corta' => $service['short_description'] ?? null,
            'Duración (minutos)' => $service['duration_minutes'] ?? null,
            'Precio referencial' => $service['price'] ?? null,
            'Notas adicionales de la doctora' => $service['notes'] ?? null,
        ];

        foreach ($fields as $label => $value) {
            if (filled($value)) {
                $lines[] = "- {$label}: {$value}";
            }
        }

        return implode("\n", $lines);
    }
}
