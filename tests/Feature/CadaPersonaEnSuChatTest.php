<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Support\PatientLeads;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Noviembre de 2026: la doctora contestaba en un chat y «la paciente no lo
 * recibía». Dos causas:
 *
 * 1. Quien escribía por primera vez con un número nuevo se emparejaba POR
 *    NOMBRE con cualquier homónimo, aunque ese ya tuviera otro teléfono. Todos
 *    los «Juan» o los que tienen «.» de nombre de perfil acababan en un solo
 *    chat (uno llegó a tener diez personas), y la respuesta a mano salía al
 *    teléfono del dueño del chat: Meta la rechazaba con 131047, o se la llevaba
 *    otra paciente.
 * 2. La bandeja mandaba el teléfono tal como estaba guardado. A diez dígitos
 *    («321 4789448», como lo deja la agenda) Meta lo leía como +32 y lo
 *    rebotaba con 131026.
 */
class CadaPersonaEnSuChatTest extends TestCase
{
    use RefreshDatabase;

    private const LINEA = '1338308346036200';

    private User $doctora;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp.token', 'token-de-prueba');
        config()->set('services.whatsapp.phone_id', self::LINEA);
        config()->set('services.whatsapp.api_version', 'v21.0');

        // Con Lore apagada el mensaje igual se guarda en su chat, y no hace falta IA.
        Settings::put('whatsapp_bot_enabled', '0');
        Settings::put('whatsapp_test_numbers', '');

        $this->doctora = User::factory()->create();
    }

    private function llega(string $de, string $nombreDePerfil, string $texto = 'Hola'): void
    {
        (new ProcessWhatsAppMessage(from: $de, text: $texto, profileName: $nombreDePerfil, phoneNumberId: self::LINEA))->handle();
    }

    private function chatDe(string $telefono): ?Conversation
    {
        $lead = Lead::where('phone', $telefono)->first();

        return $lead ? Conversation::where('lead_id', $lead->id)->first() : null;
    }

    public function test_un_homonimo_con_otro_numero_tiene_su_propio_chat(): void
    {
        $this->llega('573001112233', 'Juan');
        $this->llega('573009998877', 'Juan', 'Quiero una cita');

        $this->assertSame(2, Lead::count());
        $this->assertNotSame($this->chatDe('573001112233')->id, $this->chatDe('573009998877')->id);
        $this->assertSame(['Quiero una cita'], $this->chatDe('573009998877')->messages()->pluck('content')->all());
        $this->assertSame(['Hola'], $this->chatDe('573001112233')->messages()->pluck('content')->all());
    }

    public function test_los_perfiles_llamados_punto_no_se_juntan(): void
    {
        $this->llega('573001112233', '.');
        $this->llega('573002223344', '.');
        $this->llega('573003334455', '.');

        $this->assertSame(3, Lead::count());
        $this->assertSame(3, Conversation::count());
    }

    public function test_un_nombre_de_varias_palabras_tampoco_junta_numeros_distintos(): void
    {
        $this->llega('573001112233', 'Diana Carolina Cifuentes');
        $this->llega('573009998877', 'Diana Carolina Cifuentes Garzón');

        $this->assertSame(2, Lead::count());
    }

    public function test_sigue_completando_a_la_paciente_del_calendario_que_no_tenia_numero(): void
    {
        $delCalendario = Lead::create(['user_id' => $this->doctora->id, 'name' => 'Nubia Andrea Pinzón', 'phone' => null]);

        $this->llega('573001112233', 'Nubia Andrea Pinzón González');

        $this->assertSame(1, Lead::count());
        $this->assertSame('573001112233', $delCalendario->fresh()->phone);
    }

    public function test_el_mismo_numero_sigue_siendo_la_misma_paciente_aunque_cambie_de_nombre(): void
    {
        $this->llega('573001112233', 'Kathe');
        $this->llega('573001112233', 'Katherine Durán');

        $this->assertSame(1, Lead::count());
        $this->assertSame(1, Conversation::count());
    }

    public function test_sin_telefono_la_agenda_sigue_emparejando_por_nombre(): void
    {
        $lead = Lead::create(['user_id' => $this->doctora->id, 'name' => 'Marjko Pérez Gómez', 'phone' => '573001112233']);

        $this->assertSame($lead->id, PatientLeads::find($this->doctora, 'MARKO PEREZ GOMEZ')?->id);
    }

    public function test_la_respuesta_a_mano_sale_con_indicativo_aunque_el_telefono_este_guardado_a_diez_digitos(): void
    {
        $lead = Lead::create(['user_id' => $this->doctora->id, 'name' => 'Katherine', 'phone' => '321 4789448']);
        $chat = Conversation::create([
            'user_id' => $this->doctora->id, 'lead_id' => $lead->id, 'channel' => 'whatsapp',
            'title' => 'Katherine', 'phone_number_id' => self::LINEA,
        ]);
        $chat->messages()->create(['role' => 'user', 'content' => 'Confirmo']);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        $this->actingAs($this->doctora)
            ->post(route('inbox.send', $chat), ['content' => '¿Puedes estar a la 1:45 pm?'])
            ->assertRedirect()
            ->assertSessionMissing('error');

        Http::assertSent(fn ($req) => $req['to'] === '573214789448');
        Http::assertNotSent(fn ($req) => $req['to'] === '321 4789448' || $req['to'] === '3214789448');
    }

    public function test_con_un_telefono_invalido_avisa_en_vez_de_enviar_al_vacio(): void
    {
        $lead = Lead::create(['user_id' => $this->doctora->id, 'name' => 'Gloria', 'phone' => '321250875']);
        $chat = Conversation::create([
            'user_id' => $this->doctora->id, 'lead_id' => $lead->id, 'channel' => 'whatsapp',
            'title' => 'Gloria', 'phone_number_id' => self::LINEA,
        ]);
        $chat->messages()->create(['role' => 'user', 'content' => 'Hola']);

        Http::fake();

        $this->actingAs($this->doctora)
            ->post(route('inbox.send', $chat), ['content' => 'Hola Gloria'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'no es válido'));

        Http::assertNothingSent();
        $this->assertSame(0, $chat->messages()->where('role', 'assistant')->count());
    }
}
