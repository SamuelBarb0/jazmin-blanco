<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La bandeja se refresca sola cada 5 segundos y reenviaba la lista COMPLETA.
 *
 * Medido en producción el 28-ago-2026: 332 chats, 242 KB de JSON por refresco,
 * unos 170 MB por hora de pestaña abierta. La consulta no era el problema —13 ms
 * con los índices puestos—, el problema era el peso, y encima crecía con cada
 * paciente nueva. El panel del chat ya se había recortado por esto mismo
 * (`MAX_MENSAJES`); la lista se había quedado sin recortar.
 *
 * Lo que no se puede perder al recortar: los chats que piden atención. Pueden
 * llevar días quietos —o sea, caer al fondo de la lista— y son justo los que
 * nadie debe pasar por alto.
 */
class LaBandejaNoSeReenviaEnteraTest extends TestCase
{
    use RefreshDatabase;

    private User $doctora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctora = User::factory()->create();
    }

    /** Un chat con un mensaje fechado, para que la lista tenga cómo ordenarse. */
    private function chat(string $nombre, int $minutosAtras, array $extra = [], string $role = 'assistant'): Conversation
    {
        $lead = Lead::create([
            'user_id' => $this->doctora->id,
            'name' => $nombre,
            'phone' => '5731300'.str_pad((string) $minutosAtras, 5, '0', STR_PAD_LEFT),
        ]);

        $conversacion = Conversation::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $lead->id,
            'channel' => 'whatsapp',
            'title' => $nombre,
        ] + $extra);

        $mensaje = Message::create([
            'conversation_id' => $conversacion->id,
            'role' => $role,
            'content' => 'Mensaje de '.$nombre,
        ]);

        $cuando = now()->subMinutes($minutosAtras);
        Message::whereKey($mensaje->id)->update(['created_at' => $cuando, 'updated_at' => $cuando]);

        return $conversacion;
    }

    public function test_solo_manda_las_mas_recientes_y_dice_cuantas_hay_en_total(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->chat("Paciente {$i}", $i);
        }

        $this->actingAs($this->doctora)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('total', 60)
                ->has('conversations', 50)
                // Las 50 más recientes, no unas cualesquiera.
                ->where('conversations.0.title', 'Paciente 1'));
    }

    /**
     * El caso que hace peligroso recortar: una paciente escribió, el chat está
     * en pausa y nadie le contestó. Lleva días quieta, así que por antigüedad se
     * cae de la lista — y es la que no puede caerse.
     */
    public function test_una_paciente_sin_responder_entra_aunque_sea_muy_vieja(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->chat("Paciente {$i}", $i);
        }

        $olvidada = $this->chat('Olvidada', 60 * 24 * 7, ['bot_enabled' => false], role: 'user');

        $this->actingAs($this->doctora)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('conversations', 51)
                ->where('conversations.50.id', $olvidada->id)
                ->where('conversations.50.sin_responder', true));
    }

    /** Un chat escalado tampoco: la gracia del escalamiento es que se vea. */
    public function test_un_chat_escalado_entra_aunque_sea_muy_viejo(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->chat("Paciente {$i}", $i);
        }

        $escalado = $this->chat('Escalada', 60 * 24 * 7);
        $escalado->forceFill(['escalated_at' => now()->subWeek()])->save();

        $this->actingAs($this->doctora)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('conversations', 51)
                ->where('conversations.50.id', $escalado->id));
    }

    /** Buscando se mira el histórico entero, que para eso está el buscador. */
    public function test_el_buscador_sigue_llegando_a_los_chats_recortados(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->chat("Paciente {$i}", $i);
        }

        $vieja = $this->chat('Rosalba Quintero', 60 * 24 * 30);

        $this->actingAs($this->doctora)
            ->get(route('inbox.index', ['q' => 'Rosalba']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('conversations', 1)
                ->where('conversations.0.id', $vieja->id));
    }

    /** La fila la recorta el CSS a una línea: mandar el mensaje entero es tirar datos. */
    public function test_la_vista_previa_va_recortada(): void
    {
        $conversacion = $this->chat('Marcela', 1);
        Message::create([
            'conversation_id' => $conversacion->id,
            'role' => 'user',
            'content' => str_repeat('a', 500),
        ]);

        $this->actingAs($this->doctora)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('conversations.0.preview', fn ($preview) => mb_strlen($preview) <= 123));
    }
}
