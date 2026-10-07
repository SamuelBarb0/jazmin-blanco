<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Dos números atendidos por la misma Lore.
 *
 * Las respuestas de Lore ya salían por la línea que recibió el mensaje, pero
 * todo lo que sale por iniciativa nuestra (recordatorios, aviso de cita,
 * reactivación, respuesta a mano desde la bandeja) seguía saliendo por la del
 * `.env`. A quien escribió al segundo número le llegaba el recordatorio desde
 * un número que no conoce — y, dentro de la ventana de 24 h, Meta lo rechaza
 * con `131047` porque en esa línea nunca abrió chat.
 */
class DosLineasDeWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private const PRINCIPAL = '111111111111111';

    private const SEGUNDA = '222222222222222';

    private User $doctora;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp.token', 'token-de-prueba');
        config()->set('services.whatsapp.phone_id', self::PRINCIPAL);
        config()->set('services.whatsapp.api_version', 'v21.0');

        Settings::put('reminders_enabled', '1');
        Settings::put('whatsapp_bot_enabled', '1');
        Settings::put('whatsapp_test_numbers', '');

        $this->doctora = User::factory()->create();
    }

    private function chat(string $telefono, string $linea, int $horasDesdeSuMensaje = 1, ?Lead $lead = null): Conversation
    {
        $lead ??= Lead::create(['user_id' => $this->doctora->id, 'name' => 'Paciente', 'phone' => $telefono]);

        $conversacion = Conversation::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $lead->id,
            'channel' => 'whatsapp',
            'title' => 'Paciente',
            'phone_number_id' => $linea,
        ]);

        // `created_at` no está en el `$fillable` de Message: hay que forzarlo.
        $mensaje = $conversacion->messages()->create(['role' => 'user', 'content' => 'hola']);
        $mensaje->forceFill(['created_at' => now()->subHours($horasDesdeSuMensaje)])->save();

        return $conversacion;
    }

    public function test_encuentra_la_linea_por_lead(): void
    {
        $chat = $this->chat('573001112233', self::SEGUNDA);

        $this->assertSame(self::SEGUNDA, Conversation::lineaDeLaPaciente($this->doctora->id, $chat->lead_id, null));
    }

    public function test_encuentra_la_linea_por_telefono_aunque_la_cita_no_tenga_lead(): void
    {
        $this->chat('573001112233', self::SEGUNDA);

        // La cita se escribió a mano, sin indicativo.
        $this->assertSame(self::SEGUNDA, Conversation::lineaDeLaPaciente($this->doctora->id, null, '300 111 2233'));
    }

    public function test_gana_la_linea_por_la_que_escribio_mas_recientemente(): void
    {
        $lead = Lead::create(['user_id' => $this->doctora->id, 'name' => 'Paciente', 'phone' => '573001112233']);
        $this->chat('573001112233', self::PRINCIPAL, horasDesdeSuMensaje: 1, lead: $lead);
        $this->chat('573001112233', self::SEGUNDA, horasDesdeSuMensaje: 30, lead: $lead);

        $this->assertSame(self::PRINCIPAL, Conversation::lineaDeLaPaciente($this->doctora->id, $lead->id, null));
    }

    public function test_no_mira_las_conversaciones_de_otra_cuenta(): void
    {
        $otra = User::factory()->create();
        $lead = Lead::create(['user_id' => $otra->id, 'name' => 'Ajena', 'phone' => '573001112233']);
        Conversation::create([
            'user_id' => $otra->id, 'lead_id' => $lead->id, 'channel' => 'whatsapp',
            'title' => 'Ajena', 'phone_number_id' => self::SEGUNDA,
        ]);

        $this->assertNull(Conversation::lineaDeLaPaciente($this->doctora->id, null, '573001112233'));
    }

    public function test_quien_nunca_escribio_no_tiene_linea(): void
    {
        $this->assertNull(Conversation::lineaDeLaPaciente($this->doctora->id, null, '573009998877'));
        $this->assertNull(Conversation::lineaDeLaPaciente($this->doctora->id, null, null));
    }

    public function test_el_recordatorio_sale_por_la_linea_de_la_paciente(): void
    {
        $chat = $this->chat('573001112233', self::SEGUNDA);

        Appointment::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $chat->lead_id,
            'patient_name' => 'Paciente',
            'patient_phone' => '3001112233',
            'starts_at' => now()->addHours(10),
            'ends_at' => now()->addHours(11),
            'status' => 'scheduled',
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        $this->artisan('appointments:send-reminders --force')->assertSuccessful();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/'.self::SEGUNDA.'/messages'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/'.self::PRINCIPAL.'/messages'));
    }

    public function test_el_recordatorio_de_quien_nunca_escribio_sale_por_la_principal(): void
    {
        Appointment::create([
            'user_id' => $this->doctora->id,
            'patient_name' => 'Paciente de la agenda',
            'patient_phone' => '3009998877',
            'starts_at' => now()->addHours(10),
            'ends_at' => now()->addHours(11),
            'status' => 'scheduled',
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        $this->artisan('appointments:send-reminders --force')->assertSuccessful();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/'.self::PRINCIPAL.'/messages'));
    }

    public function test_una_linea_con_plantillas_en_pausa_no_llama_a_meta(): void
    {
        Settings::put('whatsapp_lineas_sin_plantillas', self::SEGUNDA);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        $pausada = \App\Services\WhatsAppService::fromConfig()->forPhone(self::SEGUNDA);

        $this->assertFalse($pausada->sendTemplate('573001112233', 'recordatorio_confirmar'));
        Http::assertNothingSent();

        // El texto libre (respuestas dentro de las 24 h) no se frena.
        $this->assertTrue($pausada->sendText('573001112233', 'hola'));
    }

    public function test_la_pausa_de_una_linea_no_frena_a_la_otra(): void
    {
        Settings::put('whatsapp_lineas_sin_plantillas', self::SEGUNDA);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        $this->assertTrue(\App\Services\WhatsAppService::fromConfig()->sendTemplate('573001112233', 'recordatorio_confirmar'));
        Http::assertSent(fn ($req) => str_contains($req->url(), '/'.self::PRINCIPAL.'/messages'));
    }

    public function test_el_recordatorio_en_linea_pausada_se_salta_sin_reservar(): void
    {
        Settings::put('whatsapp_lineas_sin_plantillas', self::SEGUNDA);
        Settings::put('reminder_template', 'recordatorio_confirmar');
        $chat = $this->chat('573001112233', self::SEGUNDA);

        $cita = Appointment::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $chat->lead_id,
            'patient_name' => 'Paciente',
            'patient_phone' => '3001112233',
            'starts_at' => now()->addHours(10),
            'ends_at' => now()->addHours(11),
            'status' => 'scheduled',
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        $this->artisan('appointments:send-reminders --force')
            ->expectsOutputToContain('línea sin plantillas')
            ->assertSuccessful();

        Http::assertNothingSent();
        // Sin marca: cuando se quite la pausa, la corrida siguiente lo envía.
        $this->assertNull($cita->fresh()->reminder_24h_sent_at);
    }

    public function test_una_linea_reemplazada_sale_por_su_id_nuevo(): void
    {
        Settings::put('whatsapp_lineas_reemplazadas', '999999999999999='.self::SEGUNDA);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        \App\Services\WhatsAppService::fromConfig()->forPhone('999999999999999')->sendText('573001112233', 'hola');

        Http::assertSent(fn ($req) => str_contains($req->url(), '/'.self::SEGUNDA.'/messages'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/999999999999999/'));
    }

    public function test_la_bandeja_separa_los_chats_por_numero(): void
    {
        $this->chat('573001112233', self::PRINCIPAL);
        $this->chat('573004445566', self::SEGUNDA);
        $this->chat('573007778899', self::SEGUNDA);

        // Sin filtro: todos, con una pestaña por número y su cuenta.
        $this->actingAs($this->doctora)->get(route('inbox.index', ['lista' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('conversations', 3)
                ->has('lineas', 2)
                ->where('lineas.0.id', self::SEGUNDA)
                ->where('lineas.0.chats', 2));

        // Con filtro: solo los de ese número, y las pestañas siguen siendo dos.
        $this->actingAs($this->doctora)->get(route('inbox.index', ['lista' => 1, 'linea' => self::SEGUNDA]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('conversations', 2)
                ->where('total', 2)
                ->where('linea', self::SEGUNDA)
                ->has('lineas', 2));
    }

    public function test_un_numero_reemplazado_junta_sus_chats_viejos_y_nuevos(): void
    {
        Settings::put('whatsapp_lineas_reemplazadas', '999999999999999='.self::SEGUNDA);
        Settings::put('whatsapp_lineas_nombres', self::SEGUNDA.'=317 045 2356');
        $this->chat('573001112233', '999999999999999');
        $this->chat('573004445566', self::SEGUNDA);

        $this->actingAs($this->doctora)->get(route('inbox.index', ['lista' => 1, 'linea' => self::SEGUNDA]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('conversations', 2)
                ->where('conversations.0.linea', '317 045 2356')
                ->has('lineas', 1));
    }

    public function test_la_respuesta_a_mano_sale_por_la_linea_del_chat(): void
    {
        $chat = $this->chat('573001112233', self::SEGUNDA);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        $this->actingAs($this->doctora)
            ->post(route('inbox.send', $chat), ['content' => 'Hola, te escribe la doctora'])
            ->assertRedirect();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/'.self::SEGUNDA.'/messages'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/'.self::PRINCIPAL.'/messages'));
    }
}
