<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\ReminderOptOut;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Mensaje de reactivación a quien preguntó por WhatsApp y nunca agendó.
 *
 * Lo que se fija aquí no es "que se envíe" —eso es lo fácil— sino a quién NO se
 * le envía: es un mensaje comercial, y cada destinatario de más es alguien que
 * puede reportar el número y bajarle la calidad a la línea de la doctora.
 */
class ReactivacionDeLeadsTest extends TestCase
{
    use RefreshDatabase;

    private User $doctora;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp.token', 'token-de-prueba');
        config()->set('services.whatsapp.phone_id', '111111111111111');

        $this->doctora = User::factory()->create();

        Settings::setWhatsappBotEnabled(true);
        Settings::setReactivationConfig(['enabled' => true, 'hours' => 48, 'max_per_run' => 20]);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);
    }

    /** Conversación de WhatsApp con un último mensaje entrante hace $horas. */
    private function chatFrio(string $nombre, string $telefono, int $horas): Conversation
    {
        $lead = Lead::create(['user_id' => $this->doctora->id, 'name' => $nombre, 'phone' => $telefono]);

        $conv = Conversation::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $lead->id,
            'channel' => 'whatsapp',
            'title' => 'WhatsApp · '.$nombre,
        ]);

        $this->mensajeEnvejecido($conv, 'user', 'Hola, quiero información', $horas);

        return $conv;
    }

    /**
     * Un mensaje con fecha pasada.
     *
     * El `created_at` NO se puede pasar por `Message::create()`: no está en el
     * `$fillable`, así que se descarta en silencio y el mensaje nace con la
     * fecha de hoy — con lo cual ninguna conversación llega nunca al umbral y
     * las pruebas dicen "no se envía nada" por el motivo equivocado.
     */
    private function mensajeEnvejecido(Conversation $conv, string $role, string $texto, int $horas): void
    {
        Message::create(['conversation_id' => $conv->id, 'role' => $role, 'content' => $texto])
            ->forceFill(['created_at' => now()->subHours($horas)])
            ->save();
    }

    private function correr(array $opciones = []): void
    {
        \Illuminate\Support\Facades\Artisan::call('conversations:send-reactivation', $opciones + ['--force' => true]);
    }

    public function test_le_escribe_por_plantilla_a_quien_lleva_mas_del_umbral_en_silencio(): void
    {
        $conv = $this->chatFrio('Ana', '3001112233', 72);

        $this->correr();

        Http::assertSent(fn ($req) => ($req->data()['type'] ?? null) === 'template'
            && $req->data()['template']['name'] === 'reactivacion_lead'
            // Con indicativo: sin el 57 Meta acepta con 200 y rebota con 131026.
            && $req->data()['to'] === '573001112233');

        $this->assertNotNull($conv->fresh()->reactivation_sent_at);
    }

    public function test_no_le_escribe_a_quien_todavia_esta_dentro_del_umbral(): void
    {
        $this->chatFrio('Ana', '3001112233', 12);

        $this->correr();

        Http::assertNothingSent();
    }

    public function test_no_le_escribe_a_quien_ya_agendo(): void
    {
        $conv = $this->chatFrio('Ana', '3001112233', 72);

        Appointment::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $conv->lead_id,
            'patient_name' => 'Ana',
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHour(),
            'status' => 'scheduled',
        ]);

        $this->correr();

        Http::assertNothingSent();
    }

    /**
     * Las citas importadas de la agenda de la doctora no siempre traen lead: sin
     * el cruce por teléfono, a una paciente que YA tiene cita se le mandaría un
     * «¿aún podemos ayudarte?».
     */
    public function test_no_le_escribe_a_quien_tiene_cita_sin_lead_vinculado(): void
    {
        $this->chatFrio('Ana', '3001112233', 72);

        Appointment::create([
            'user_id' => $this->doctora->id,
            'patient_name' => 'Ana',
            'patient_phone' => '573001112233',
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHour(),
            'status' => 'scheduled',
        ]);

        $this->correr();

        Http::assertNothingSent();
    }

    public function test_no_se_repite_en_la_siguiente_corrida(): void
    {
        $this->chatFrio('Ana', '3001112233', 72);

        $this->correr();
        Http::assertSentCount(1);

        // El silencio solo crece: sin la marca, la corrida de la hora siguiente
        // se lo volvería a mandar, y otra vez a la siguiente.
        $this->correr();
        Http::assertSentCount(1);
    }

    public function test_no_le_escribe_a_un_chat_escalado_a_humano(): void
    {
        $conv = $this->chatFrio('Ana', '3001112233', 72);
        $conv->forceFill(['escalated_at' => now()->subHours(50)])->save();

        $this->correr();

        Http::assertNothingSent();
    }

    public function test_no_le_escribe_a_quien_pidio_no_recibir_mensajes(): void
    {
        $conv = $this->chatFrio('Ana', '3001112233', 72);

        ReminderOptOut::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $conv->lead_id,
            'phone' => ReminderOptOut::normalize('3001112233'),
            'source' => 'whatsapp',
        ]);

        $this->correr();

        Http::assertNothingSent();
    }

    public function test_el_interruptor_general_lo_detiene(): void
    {
        $this->chatFrio('Ana', '3001112233', 72);
        Settings::setWhatsappBotEnabled(false);

        $this->correr();

        Http::assertNothingSent();
    }

    public function test_las_conversaciones_del_playground_no_cuentan(): void
    {
        $conv = $this->chatFrio('Ana', '3001112233', 72);
        $conv->forceFill(['channel' => 'panel'])->save();

        $this->correr();

        Http::assertNothingSent();
    }

    /** Sin ningún mensaje suyo no hay interés que reactivar. */
    public function test_una_conversacion_sin_mensajes_entrantes_no_recibe_nada(): void
    {
        $lead = Lead::create(['user_id' => $this->doctora->id, 'name' => 'Ana', 'phone' => '3001112233']);
        $conv = Conversation::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $lead->id,
            'channel' => 'whatsapp',
            'title' => 'WhatsApp · Ana',
        ]);
        $this->mensajeEnvejecido($conv, 'assistant', 'Le escribimos nosotros primero', 72);

        $this->correr();

        Http::assertNothingSent();
    }

    /**
     * El freno que importa el día que se encienda: la marca nace vacía, así que
     * la primera corrida ve TODO el histórico frío como pendiente.
     */
    public function test_el_tope_por_corrida_reparte_el_atasco(): void
    {
        foreach (range(1, 5) as $i) {
            $this->chatFrio("Paciente {$i}", '30011122'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 72 + $i);
        }

        Settings::setReactivationConfig(['max_per_run' => 2]);
        $this->correr();
        Http::assertSentCount(2);

        $this->correr();
        Http::assertSentCount(4);
    }

    /** Los más recientes primero: un silencio de 2 días se recupera mejor que uno de 3 meses. */
    public function test_entra_primero_el_silencio_mas_corto(): void
    {
        $this->chatFrio('Vieja', '3001110000', 24 * 90);
        $this->chatFrio('Reciente', '3001119999', 72);

        Settings::setReactivationConfig(['max_per_run' => 1]);
        $this->correr();

        Http::assertSent(fn ($req) => $req->data()['to'] === '573001119999');
        Http::assertNotSent(fn ($req) => $req->data()['to'] === '573001110000');
    }

    public function test_la_simulacion_no_envia_ni_marca_nada(): void
    {
        $conv = $this->chatFrio('Ana', '3001112233', 72);

        $this->correr(['--dry-run' => true]);

        Http::assertNothingSent();
        $this->assertNull($conv->fresh()->reactivation_sent_at);
    }

    /** Lo que lee la doctora en el CRM tiene que ser lo que recibió la paciente. */
    public function test_el_mensaje_queda_en_el_historial_del_chat(): void
    {
        $conv = $this->chatFrio('Ana', '3001112233', 72);

        $this->correr();

        $ultimo = $conv->messages()->where('role', 'assistant')->latest('id')->first();

        $this->assertNotNull($ultimo);
        $this->assertStringContainsString('aún podemos ayudarte', $ultimo->content);
        $this->assertStringContainsString('agendar una valoración con la Dra. Jasmin Blanco', $ultimo->content);
    }

    /**
     * La decisión de la doctora al encenderlo: los 104 chats ya fríos no
     * reciben nada, solo los que se enfríen a partir de ahora.
     */
    public function test_la_fecha_de_corte_deja_fuera_a_los_que_ya_estaban_frios(): void
    {
        $this->chatFrio('Vieja', '3001110000', 24 * 30);
        $this->chatFrio('Nueva', '3001119999', 72);

        // Se enciende "ahora", con 96 h de historia por detrás: la de 72 h entra,
        // la de 30 días no.
        Settings::setReactivationConfig(['min_inbound_at' => now()->subHours(96)]);

        $this->correr();

        Http::assertSentCount(1);
        Http::assertSent(fn ($req) => $req->data()['to'] === '573001119999');
    }

    /** Sin fecha de corte se comporta como antes: entra todo el histórico. */
    public function test_sin_fecha_de_corte_entra_todo_el_historico(): void
    {
        $this->chatFrio('Vieja', '3001110000', 24 * 30);

        Settings::setReactivationConfig(['min_inbound_at' => null]);

        $this->correr();

        Http::assertSentCount(1);
    }

    /**
     * Quitar el ajuste devuelve la cola. Es lo que hace que la decisión sea
     * reversible sin haber tocado ni una fila de datos.
     */
    public function test_borrar_la_fecha_de_corte_devuelve_la_cola(): void
    {
        $this->chatFrio('Vieja', '3001110000', 24 * 30);

        Settings::setReactivationConfig(['min_inbound_at' => now()->subHours(96)]);
        $this->correr();
        Http::assertNothingSent();

        Settings::setReactivationConfig(['min_inbound_at' => null]);
        $this->correr();
        Http::assertSentCount(1);
    }

    public function test_apagada_en_la_configuracion_no_hace_nada(): void
    {
        $this->chatFrio('Ana', '3001112233', 72);
        Settings::setReactivationConfig(['enabled' => false]);

        $this->correr();

        Http::assertNothingSent();
    }
}
