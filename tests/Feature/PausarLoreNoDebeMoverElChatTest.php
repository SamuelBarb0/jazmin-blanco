<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Después de pausar a Lore no puedo enviarle mensajes al paciente; a veces
 * funciona y a veces no, y en el celular casi no me pasa» (la doctora, 31-ago).
 *
 * En pantalla grande la bandeja abre sola el chat más reciente y la URL se
 * queda en `/inbox`, sin id. Tanto el botón de pausar como el de enviar
 * terminaban en `back()`, que devuelve a esa misma URL sin id: el servidor
 * volvía a preguntarse cuál era «el más reciente de la clínica» y, si mientras
 * tanto había escrito OTRA paciente, le cambiaba el chat debajo. La doctora
 * pausaba a Lore en un chat y se encontraba escribiéndole a otra persona —o con
 * la caja gris, porque el 91% de los chats tiene la ventana de 24 h cerrada.
 *
 * En el celular casi no pasa porque ahí el relleno se oculta y siempre abre los
 * chats a mano, así que el id va en la URL y no hay nada que adivinar.
 *
 * La cura es no depender del `back()`: quien actúa sobre una conversación
 * concreta vuelve a ESA conversación.
 */
class PausarLoreNoDebeMoverElChatTest extends TestCase
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

    public function test_pausar_a_lore_devuelve_al_mismo_chat_y_no_al_mas_reciente(): void
    {
        // La que tiene abierta en pantalla, sin id en la URL (relleno).
        $atendiendo = $this->chat('La que está atendiendo', 20);
        // Y mientras tanto escribe otra: pasa a ser «la más reciente».
        $otra = $this->chat('La que acaba de escribir', 1);

        $respuesta = $this->actingAs($this->doctora)
            ->from(route('inbox.index'))
            ->patch(route('inbox.toggle', $atendiendo));

        $this->followRedirects($respuesta)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Lo que fallaba: aquí llegaba $otra y se le cambiaba el chat.
                ->where('selected.id', $atendiendo->id)
                ->where('selected.bot_enabled', false));

        $this->assertNotSame($otra->id, $atendiendo->id);
    }

    public function test_enviar_un_mensaje_tampoco_mueve_el_chat(): void
    {
        $atendiendo = $this->chat('La que está atendiendo', 20);
        $this->chat('La que acaba de escribir', 1);

        // Sin teléfono válido el envío se corta antes de llamar a Meta, pero el
        // redirect es el mismo y es lo que se está comprobando.
        $atendiendo->lead->update(['phone' => '']);

        $respuesta = $this->actingAs($this->doctora)
            ->from(route('inbox.index'))
            ->post(route('inbox.send', $atendiendo), ['content' => 'Hola']);

        $this->followRedirects($respuesta)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('selected.id', $atendiendo->id));
    }

    public function test_el_chat_queda_fijado_para_que_los_refrescos_no_lo_muevan(): void
    {
        $atendiendo = $this->chat('La que está atendiendo', 20);
        $this->chat('La que acaba de escribir', 1);

        // Tras actuar sobre un chat, la URL deja de ser ambigua: el id queda
        // puesto y ni el refresco de cada 5 s ni un mensaje nuevo de otra
        // paciente pueden reemplazarlo.
        $this->actingAs($this->doctora)
            ->from(route('inbox.index'))
            ->patch(route('inbox.toggle', $atendiendo))
            ->assertRedirect(route('inbox.show', $atendiendo));
    }
}
