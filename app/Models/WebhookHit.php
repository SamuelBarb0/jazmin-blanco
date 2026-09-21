<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Un evento que Meta nos entregó por el webhook. Ver la migración para el
 * porqué.
 */
class WebhookHit extends Model
{
    use Prunable;

    /** Se anotó la llegada; todavía no se sabe qué pasó después. */
    public const RESULTADO_RECIBIDO = 'recibido';

    /** Meta lo reintentó y ya lo teníamos: no se procesa dos veces. */
    public const RESULTADO_DUPLICADO = 'duplicado';

    /** Encolado para procesar. */
    public const RESULTADO_ENCOLADO = 'encolado';

    /** Guardado en la conversación (aunque nadie lo haya respondido). */
    public const RESULTADO_GUARDADO = 'guardado';

    /** Guardado y contestado por Lore. */
    public const RESULTADO_RESPONDIDO = 'respondido';

    /** Llegó, se decidió no responder, y el detalle dice por qué. */
    public const RESULTADO_IGNORADO = 'ignorado';

    /** Reventó al procesarlo. */
    public const RESULTADO_ERROR = 'error';

    protected $fillable = [
        'wamid', 'from_phone', 'phone_number_id', 'tipo',
        'resultado', 'detalle', 'conversation_id',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Noventa días. Es prueba de entrega, no archivo: pasado ese plazo el caso
     * que se quería demostrar ya se cerró, y la tabla crece con cada mensaje
     * que entra.
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(90));
    }

    /**
     * Anota lo que acabó pasando con este evento.
     *
     * Nunca lanza: el rastro es una ayuda de diagnóstico y no puede ser el
     * motivo de que una paciente se quede sin respuesta. Si la tabla no está
     * (migración aún sin correr) o la base falla, se sigue adelante.
     */
    public function marcar(string $resultado, ?string $detalle = null, ?int $conversationId = null): void
    {
        try {
            $this->forceFill(array_filter([
                'resultado' => $resultado,
                'detalle' => $detalle,
                'conversation_id' => $conversationId,
            ], fn ($v) => $v !== null))->save();
        } catch (Throwable $e) {
            Log::warning('No se pudo marcar el rastro del webhook.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Busca el rastro por el id de Meta, para que el job pueda marcarlo sin
     * arrastrar el modelo entero por la cola.
     */
    public static function porWamid(?string $wamid): ?self
    {
        if (blank($wamid)) {
            return null;
        }

        try {
            return static::where('wamid', $wamid)->latest('id')->first();
        } catch (Throwable $e) {
            Log::warning('No se pudo leer el rastro del webhook.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Deja constancia de un evento recién llegado.
     *
     * Se llama lo antes posible y en su propio try/catch por la misma razón:
     * si esto falla, el mensaje tiene que seguir su camino igual.
     */
    public static function anotar(array $datos): ?self
    {
        try {
            return static::create($datos + ['resultado' => self::RESULTADO_RECIBIDO]);
        } catch (Throwable $e) {
            Log::warning('No se pudo anotar la llegada del webhook.', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
