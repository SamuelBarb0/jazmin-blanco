<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * En pantalla grande la bandeja abre sola el chat más reciente para no dejar el
 * panel derecho vacío. Ese chat NO lleva id en la URL —la dirección es `/inbox`
 * a secas—, así que cada vez que el navegador recargaba el panel, el servidor
 * volvía a preguntarse cuál era «el más reciente de la clínica».
 *
 * Si mientras tanto escribía otra paciente, la respuesta cambiaba: a la doctora
 * se le cambiaba la conversación debajo, pudiendo acabar leyendo —o
 * escribiendo— en el chat equivocado. Al abrir un chat a mano nunca pasó, porque
 * ahí el id va en la URL y no hay nada que adivinar.
 */
class ElChatDeRellenoNoSeCambiaSoloTest extends TestCase
{
    use RefreshDatabase;

    private User $doctora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctora = User::factory()->create();
    }

    private function chat(string $nombre, int $minutosAtras): Conversation
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
        ]);

        $mensaje = Message::create([
            'conversation_id' => $conversacion->id,
            'role' => 'user',
            'content' => 'Hola, soy '.$nombre,
        ]);

        $cuando = now()->subMinutes($minutosAtras);
        Message::whereKey($mensaje->id)->update(['created_at' => $cuando, 'updated_at' => $cuando]);

        return $conversacion;
    }

    /** Sin nada que decirle, el relleno sigue siendo el chat más reciente. */
    public function test_por_defecto_abre_el_mas_reciente(): void
    {
        $this->chat('Antigua', 60);
        $reciente = $this->chat('Reciente', 1);

        $this->actingAs($this->doctora)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('selected.id', $reciente->id)
                ->where('auto_selected', true));
    }

    /** El caso del fallo: la doctora tiene abierta una y escribe OTRA paciente. */
    public function test_respeta_el_chat_que_el_navegador_ya_tiene_delante(): void
    {
        $enPantalla = $this->chat('La que está leyendo', 30);
        $this->chat('La que acaba de escribir', 1);

        $this->actingAs($this->doctora)
            ->get(route('inbox.index', ['abierta' => $enPantalla->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('selected.id', $enPantalla->id)
                // Sigue siendo relleno: en celular la lista no debe saltar al
                // chat solo porque el escritorio le pasó un id.
                ->where('auto_selected', true));
    }

    /** Un id de otra clínica no abre nada suyo: se cae al relleno de siempre. */
    public function test_un_id_ajeno_no_abre_el_chat_de_otra_clinica(): void
    {
        $otra = User::factory()->create();
        $ajena = Conversation::create([
            'user_id' => $otra->id,
            'channel' => 'whatsapp',
            'title' => 'De otra clínica',
        ]);

        $mia = $this->chat('Mía', 1);

        $this->actingAs($this->doctora)
            ->get(route('inbox.index', ['abierta' => $ajena->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('selected.id', $mia->id));
    }

    /** Buscando no se abre nada solo, con `abierta` o sin él. */
    public function test_buscando_no_abre_ningun_relleno(): void
    {
        $chat = $this->chat('Marcela', 1);

        $this->actingAs($this->doctora)
            ->get(route('inbox.index', ['q' => 'Marcela', 'abierta' => $chat->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('selected', null));
    }
}
