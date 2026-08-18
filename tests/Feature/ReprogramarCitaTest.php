<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Services\BotService;
use App\Support\PatientLeads;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Lo que pidió la doctora: que Lore pueda mover y cancelar citas ya agendadas.
 *
 * Hasta ahora el prompt decía lo contrario —«TÚ NO puedes cancelar ni
 * reprogramar»— y toda paciente que quisiera correr su cita terminaba en la
 * bandeja de escalados esperando a que una persona le escribiera.
 *
 * Lo delicado no es mover la fila: es no mover la de OTRA paciente, no dejarla
 * en una hora en la que el consultorio no atiende, y no dejar los recordatorios
 * apuntando a la fecha vieja.
 */
class ReprogramarCitaTest extends TestCase
{
    use RefreshDatabase;

    private User $doctora;

    /**
     * Un miércoles y un jueves siempre futuros (el domingo está cerrado), y a
     * más de una semana vista a propósito: con el miércoles MÁS PRÓXIMO, según
     * el día y la hora en que corriera la suite, la cita caía dentro de las 24 h
     * de la política y las pruebas del aviso se contradecían solas.
     */
    private string $miercoles;

    private string $jueves;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctora = User::factory()->create();

        // Calendario conectado por OAuth: es el camino que sí se puede fingir
        // entero con Http::fake (la cuenta de servicio exigiría firmar un JWT
        // con una llave privada de verdad).
        Settings::setGoogleOAuth('refresh-de-prueba', 'doctora@example.com');
        Settings::setGoogleOAuthCalendar('cal-citas');

        $this->miercoles = Carbon::today()->next(Carbon::WEDNESDAY)->addWeek()->format('Y-m-d');
        $this->jueves = Carbon::today()->next(Carbon::WEDNESDAY)->addWeek()->addDay()->format('Y-m-d');
    }

    public function test_mover_la_cita_cambia_la_hora_y_conserva_la_duracion(): void
    {
        $this->googleResponde();

        $cita = $this->citaDe($this->paciente(), $this->miercoles.' 10:00', 45);

        $mensaje = $this->reagendar($cita, ['fecha_hora' => $this->jueves.'T15:00']);

        $cita->refresh();
        $this->assertSame($this->jueves.' 15:00:00', $cita->starts_at->format('Y-m-d H:i:s'));
        // 45 minutos, los que ya tenía: no la duración por defecto.
        $this->assertSame($this->jueves.' 15:45:00', $cita->ends_at->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('se movió', $mensaje);
        $this->assertStringContainsString('NO tiene que volver a pagar', $mensaje);
    }

    /**
     * Las marcas son de la fecha VIEJA: si no se limpian, a quien ya recibió el
     * aviso de 24 h no le llega NINGUNO para la fecha nueva.
     */
    public function test_mover_la_cita_devuelve_los_recordatorios_a_cero(): void
    {
        $this->googleResponde();

        $cita = $this->citaDe($this->paciente(), $this->miercoles.' 10:00', 60);
        $cita->forceFill([
            'reminder_24h_sent_at' => now()->subHours(3),
            'reminder_2h_sent_at' => now()->subHour(),
        ])->save();

        $this->reagendar($cita, ['fecha_hora' => $this->jueves.'T09:00']);

        $cita->refresh();
        $this->assertNull($cita->reminder_24h_sent_at);
        $this->assertNull($cita->reminder_2h_sent_at);
    }

    public function test_el_evento_de_google_se_actualiza_en_vez_de_duplicarse(): void
    {
        $this->googleResponde();

        $cita = $this->citaDe($this->paciente(), $this->miercoles.' 10:00', 60, 'evt-1');

        $this->reagendar($cita, ['fecha_hora' => $this->jueves.'T09:00']);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_contains($request->url(), 'evt-1'));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/events'));
        $this->assertSame('evt-1', $cita->refresh()->google_event_id);
    }

    /**
     * El caso que obliga a excluir su propio evento: mientras se recalcula, la
     * cita sigue ocupando su hueco viejo en Google. Sin la excepción, correrla
     * media hora se choca CONSIGO MISMA.
     */
    public function test_la_paciente_puede_correr_su_cita_encima_de_su_propio_hueco(): void
    {
        $this->googleResponde([
            ['start' => $this->miercoles.'T10:00:00-05:00', 'end' => $this->miercoles.'T11:00:00-05:00'],
        ]);

        $cita = $this->citaDe($this->paciente(), $this->miercoles.' 10:00', 60);

        $mensaje = $this->reagendar($cita, ['fecha_hora' => $this->miercoles.'T10:30']);

        $this->assertStringNotContainsString('ERROR', $mensaje);
        $this->assertSame($this->miercoles.' 10:30:00', $cita->refresh()->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_no_se_mueve_encima_de_otra_cita(): void
    {
        $this->googleResponde([
            ['start' => $this->jueves.'T15:00:00-05:00', 'end' => $this->jueves.'T16:00:00-05:00'],
        ]);

        $cita = $this->citaDe($this->paciente(), $this->miercoles.' 10:00', 60);

        $mensaje = $this->reagendar($cita, ['fecha_hora' => $this->jueves.'T15:30']);

        $this->assertStringContainsString('ya está ocupado', $mensaje);
        // Y la cita se queda EXACTAMENTE donde estaba.
        $this->assertSame($this->miercoles.' 10:00:00', $cita->refresh()->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_no_se_mueve_al_almuerzo_de_la_doctora(): void
    {
        $this->googleResponde();

        $cita = $this->citaDe($this->paciente(), $this->miercoles.' 10:00', 60);

        $mensaje = $this->reagendar($cita, ['fecha_hora' => $this->jueves.'T12:00']);

        $this->assertStringContainsString('descanso', $mensaje);
        $this->assertSame($this->miercoles.' 10:00:00', $cita->refresh()->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_no_se_mueve_a_una_fecha_que_ya_paso(): void
    {
        $this->googleResponde();

        $cita = $this->citaDe($this->paciente(), $this->miercoles.' 10:00', 60);

        $mensaje = $this->reagendar($cita, ['fecha_hora' => Carbon::yesterday()->format('Y-m-d').'T10:00']);

        $this->assertStringContainsString('ya pasaron', $mensaje);
        $this->assertSame($this->miercoles.' 10:00:00', $cita->refresh()->starts_at->format('Y-m-d H:i:s'));
    }

    /**
     * Lo más grave que podría hacer esta herramienta: mover la cita de otra
     * persona porque quien escribe no tiene ninguna.
     */
    public function test_no_toca_la_cita_de_otra_paciente(): void
    {
        $this->googleResponde();

        $otra = Lead::create(['user_id' => $this->doctora->id, 'name' => 'Ana Otra', 'phone' => '3001112233']);
        $cita = $this->citaDe($otra, $this->miercoles.' 10:00', 60);

        $sinCitas = Lead::create(['user_id' => $this->doctora->id, 'name' => 'Sara Sincita', 'phone' => '3009998877']);

        $mensaje = $this->llamar('toolReschedule', $this->conversacionDe($sinCitas), [
            'fecha_hora' => $this->jueves.'T15:00',
        ]);

        $this->assertStringContainsString('no encuentro ninguna cita', $mensaje);
        $this->assertSame($this->miercoles.' 10:00:00', $cita->refresh()->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_con_dos_citas_pregunta_cual_antes_de_mover_ninguna(): void
    {
        $this->googleResponde();

        $paciente = $this->paciente();
        $una = $this->citaDe($paciente, $this->miercoles.' 10:00', 60);
        $otra = $this->citaDe($paciente, $this->jueves.' 10:00', 60, 'evt-2');

        $mensaje = $this->llamar('toolReschedule', $this->conversacionDe($paciente), [
            'fecha_hora' => $this->jueves.'T16:00',
        ]);

        $this->assertStringContainsString('más de una cita', $mensaje);
        $this->assertStringContainsString('cita_id '.$una->id, $mensaje);
        $this->assertSame($this->miercoles.' 10:00:00', $una->refresh()->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->jueves.' 10:00:00', $otra->refresh()->starts_at->format('Y-m-d H:i:s'));

        // Y con el id elegido sí se mueve la que corresponde.
        $this->llamar('toolReschedule', $this->conversacionDe($paciente), [
            'fecha_hora' => $this->jueves.'T16:00',
            'cita_id' => $otra->id,
        ]);

        $this->assertSame($this->miercoles.' 10:00:00', $una->refresh()->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->jueves.' 16:00:00', $otra->refresh()->starts_at->format('Y-m-d H:i:s'));
    }

    /**
     * El plazo de la política se cuenta contra la cita VIEJA, que es la que se
     * está incumpliendo. Se mueve igual, pero Lore tiene que decírselo.
     */
    public function test_mover_con_menos_de_24_horas_recuerda_la_politica(): void
    {
        $this->googleResponde();
        Carbon::setTestNow(Carbon::parse($this->miercoles.' 08:00', Settings::googleTimezone()));

        $cita = $this->citaDe($this->paciente(), $this->miercoles.' 15:00', 60);

        $mensaje = $this->reagendar($cita, ['fecha_hora' => $this->jueves.'T10:00']);

        $this->assertStringContainsString('menos de 24 horas', $mensaje);
        $this->assertSame($this->jueves.' 10:00:00', $cita->refresh()->starts_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_con_mas_de_24_horas_no_le_menciona_la_politica(): void
    {
        $this->googleResponde();

        $cita = $this->citaDe($this->paciente(), $this->miercoles.' 10:00', 60);

        $mensaje = $this->reagendar($cita, ['fecha_hora' => $this->jueves.'T10:00']);

        $this->assertStringNotContainsString('menos de 24 horas', $mensaje);
    }

    // ───────────────────────────── Cancelar ─────────────────────────────

    public function test_cancelar_saca_la_cita_de_la_agenda_y_del_calendario(): void
    {
        $this->googleResponde();

        $cita = $this->citaDe($this->paciente(), $this->miercoles.' 10:00', 60, 'evt-1');

        $mensaje = $this->llamar('toolCancel', $this->conversacionDe($cita->lead), ['motivo' => 'se va de viaje']);

        $cita->refresh();
        $this->assertSame('cancelled', $cita->status);
        $this->assertNull($cita->google_event_id);
        $this->assertStringContainsString('se va de viaje', (string) $cita->notes);
        $this->assertStringContainsString('CANCELADA', $mensaje);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), 'evt-1'));
    }

    public function test_cancelar_a_tiempo_dice_que_hay_devolucion_y_manda_escalar(): void
    {
        $this->googleResponde();

        $cita = $this->citaDe($this->paciente(), $this->miercoles.' 10:00', 60);

        $mensaje = $this->llamar('toolCancel', $this->conversacionDe($cita->lead), []);

        $this->assertStringContainsString('SÍ le corresponde la devolución', $mensaje);
        $this->assertStringContainsString('escalar_a_humano', $mensaje);
    }

    public function test_cancelar_sobre_la_hora_no_promete_devolucion(): void
    {
        $this->googleResponde();
        Carbon::setTestNow(Carbon::parse($this->miercoles.' 08:00', Settings::googleTimezone()));

        $cita = $this->citaDe($this->paciente(), $this->miercoles.' 15:00', 60);

        $mensaje = $this->llamar('toolCancel', $this->conversacionDe($cita->lead), []);

        $this->assertStringContainsString('NO se le devuelve', $mensaje);
        $this->assertSame('cancelled', $cita->refresh()->status);

        Carbon::setTestNow();
    }

    /**
     * El caso de todos los días: la cita NO la agendó Lore, la escribió la
     * doctora en el panel con el teléfono de la paciente. Cuando ese número
     * escribe por WhatsApp tiene que reconocerla como suya.
     *
     * Funciona porque los dos caminos pasan por `PatientLeads::resolve()`, que
     * empareja por los últimos dígitos: la doctora escribe «3001234567» y Meta
     * manda «573001234567», y aun así es el mismo lead.
     */
    public function test_una_cita_escrita_por_la_doctora_en_el_panel_se_puede_mover_por_whatsapp(): void
    {
        $this->googleResponde();

        // La doctora la agenda a mano, sin elegir paciente del CRM.
        $this->actingAs($this->doctora)->post(route('appointments.store'), [
            'patient_name' => 'Laura Paciente',
            'patient_phone' => '3001234567',
            'starts_at' => $this->miercoles.' 10:00',
            'duration_minutes' => 60,
        ])->assertRedirect();

        $cita = Appointment::firstOrFail();

        // Y ahora escribe ese mismo número por WhatsApp, con indicativo, como
        // lo entrega Meta: es el lead que resuelve el job del webhook.
        $lead = PatientLeads::resolve($this->doctora, 'Lau', '573001234567');

        $mensaje = $this->llamar('toolReschedule', $this->conversacionDe($lead), [
            'fecha_hora' => $this->jueves.'T15:00',
        ]);

        $this->assertStringNotContainsString('no encuentro ninguna cita', $mensaje);
        $this->assertSame($this->jueves.' 15:00:00', $cita->refresh()->starts_at->format('Y-m-d H:i:s'));
    }

    /**
     * El reverso: una cita sin teléfono y a nombre de otra persona no es de
     * quien escribe. Preferimos que Lore escale a que mueva la cita de alguien.
     */
    public function test_una_cita_sin_telefono_no_se_le_atribuye_a_cualquiera(): void
    {
        $this->googleResponde();

        $this->actingAs($this->doctora)->post(route('appointments.store'), [
            'patient_name' => 'Marcela Otra',
            'starts_at' => $this->miercoles.' 10:00',
            'duration_minutes' => 60,
        ])->assertRedirect();

        $cita = Appointment::firstOrFail();

        $quienEscribe = PatientLeads::resolve($this->doctora, 'Laura Paciente', '573001234567');

        $mensaje = $this->llamar('toolReschedule', $this->conversacionDe($quienEscribe), [
            'fecha_hora' => $this->jueves.'T15:00',
        ]);

        $this->assertStringContainsString('no encuentro ninguna cita', $mensaje);
        $this->assertSame($this->miercoles.' 10:00:00', $cita->refresh()->starts_at->format('Y-m-d H:i:s'));
    }

    // ──────────────────────── Lo que Lore sabe de entrada ────────────────────────

    /**
     * Sin esto, a un «necesito cambiar mi cita» Lore contesta preguntando
     * cuándo la tiene: un dato que el consultorio ya tiene y que la paciente
     * casi nunca recuerda con exactitud.
     */
    public function test_el_prompt_le_dice_que_la_paciente_ya_tiene_cita(): void
    {
        config()->set('services.anthropic.key', 'sk-de-prueba'); // para que canSchedule() sea true

        $cita = $this->citaDe($this->paciente(), $this->miercoles.' 10:00', 60);

        $prompt = BotService::fromUser($this->doctora)
            ->forConversation($this->conversacionDe($cita->lead))
            ->systemPrompt();

        $this->assertStringContainsString('YA tiene cita agendada', $prompt);
        $this->assertStringContainsString('cita_id '.$cita->id, $prompt);
        $this->assertStringContainsString('reagendar_cita', $prompt);
    }

    public function test_a_quien_no_tiene_cita_no_se_le_inventa_ninguna(): void
    {
        config()->set('services.anthropic.key', 'sk-de-prueba');

        $prompt = BotService::fromUser($this->doctora)
            ->forConversation($this->conversacionDe($this->paciente()))
            ->systemPrompt();

        $this->assertStringNotContainsString('YA tiene cita agendada', $prompt);
    }

    // ───────────────────────────── Andamiaje ─────────────────────────────

    /** Google acepta todo; `$busy` son los bloques ocupados que devuelve freeBusy. */
    private function googleResponde(array $busy = []): void
    {
        Http::fake([
            // La doctora agendando desde el panel le avisa a la paciente por
            // WhatsApp; aquí no interesa, pero no puede salir de verdad.
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200),
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token-falso'], 200),
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => ['cal-citas' => ['busy' => $busy]],
            ], 200),
            'www.googleapis.com/calendar/v3/calendars/*' => Http::response(['id' => 'evt-nuevo'], 200),
        ]);
    }

    private function paciente(): Lead
    {
        return Lead::create([
            'user_id' => $this->doctora->id,
            'name' => 'Laura Paciente',
            'phone' => '3001234567',
        ]);
    }

    private function citaDe(Lead $lead, string $inicio, int $minutos, string $eventId = 'evt-1'): Appointment
    {
        $start = Carbon::parse($inicio);

        return $this->doctora->appointments()->create([
            'lead_id' => $lead->id,
            'patient_name' => $lead->name,
            'patient_phone' => $lead->phone,
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'ends_at' => $start->copy()->addMinutes($minutos)->format('Y-m-d H:i:s'),
            'status' => 'scheduled',
            'google_event_id' => $eventId,
        ]);
    }

    private function conversacionDe(Lead $lead): Conversation
    {
        return Conversation::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $lead->id,
            'channel' => 'whatsapp',
            'title' => $lead->name,
        ]);
    }

    private function reagendar(Appointment $cita, array $input): string
    {
        return $this->llamar('toolReschedule', $this->conversacionDe($cita->lead), $input);
    }

    /**
     * Entra por reflexión: las herramientas son privadas porque solo las llama
     * el modelo, y abrirlas para el test cambiaría el diseño por comodidad.
     */
    private function llamar(string $metodo, Conversation $conversacion, array $input): string
    {
        $tool = new ReflectionMethod(BotService::class, $metodo);
        $tool->setAccessible(true);

        return $tool->invoke(
            BotService::fromUser($this->doctora)->forConversation($conversacion),
            $input,
        );
    }
}
