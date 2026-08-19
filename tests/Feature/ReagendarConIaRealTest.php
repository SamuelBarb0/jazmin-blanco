<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Services\BotService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Comprobación contra el modelo REAL: ¿Lore mueve la cita, o vuelve a preguntar?
 *
 * El resto de la suite finge la respuesta de Anthropic, así que valida el código
 * pero no el prompt. Esta prueba es la única que llama de verdad a la API, y por
 * eso NO corre con la suite: hay que pedirla a propósito con IA_REAL=1. Cuesta
 * dinero cada vez que se ejecuta.
 *
 *     IA_REAL=1 php artisan test --filter=ReagendarConIaReal
 *
 * WhatsApp y Google Calendar sí van falseados: lo único que sale a la red de
 * verdad es la llamada al modelo.
 *
 * Nace del caso de William Briñez (19/08/2026): pidió "reprogramar para el
 * próximo jueves a la misma hora" y Lore, en vez de moverla, respondió
 * "¿te confirmo el cambio?". Él ya lo había pedido, no contestó, y se quedó con
 * la cita vieja.
 */
class ReagendarConIaRealTest extends TestCase
{
    use RefreshDatabase;

    private User $doctora;

    protected function setUp(): void
    {
        parent::setUp();

        if (! getenv('IA_REAL')) {
            $this->markTestSkipped('Llama a la API real; se pide con IA_REAL=1.');
        }

        $this->doctora = User::factory()->create();

        Settings::setGoogleOAuth('refresh-de-prueba', 'doctora@example.com');
        Settings::setGoogleOAuthCalendar('cal-citas');
        Settings::put('whatsapp_bot_enabled', '1');

        // Todo falseado MENOS api.anthropic.com: las URL que no encajan con
        // ningún patrón salen de verdad, que es justo lo que se busca aquí.
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200),
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token-falso'], 200),
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => ['cal-citas' => ['busy' => []]],
            ], 200),
            'www.googleapis.com/calendar/v3/calendars/*' => Http::response(['id' => 'evt-nuevo'], 200),
        ]);
    }

    public function test_si_la_paciente_ya_dijo_cuando_la_mueve_sin_volver_a_preguntar(): void
    {
        // Un jueves holgadamente futuro, como en el resto de pruebas de agenda.
        $jueves = Carbon::today()->next(Carbon::THURSDAY)->addWeek();
        $siguiente = $jueves->copy()->addWeek();

        $lead = Lead::create([
            'user_id' => $this->doctora->id,
            'name' => 'William Briñez',
            'phone' => '3107730230',
        ]);

        $cita = $this->doctora->appointments()->create([
            'lead_id' => $lead->id,
            'patient_name' => $lead->name,
            'patient_phone' => $lead->phone,
            'starts_at' => $jueves->format('Y-m-d').' 15:00:00',
            'ends_at' => $jueves->format('Y-m-d').' 16:00:00',
            'status' => 'scheduled',
            'google_event_id' => 'evt-1',
        ]);

        $conversacion = Conversation::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $lead->id,
            'channel' => 'whatsapp',
            'title' => $lead->name,
        ]);

        // La misma secuencia del caso real: recordatorio y, encima, la petición.
        Message::create([
            'conversation_id' => $conversacion->id,
            'role' => 'assistant',
            'content' => 'Hola William 👋 Te recordamos tu cita mañana ('.$jueves->translatedFormat('l j \d\e F').' a las 3:00 p. m.) en Consultorio Dra. Jasmin Blanco. Si necesitas reprogramarla, respóndenos por este chat.',
        ]);
        Message::create([
            'conversation_id' => $conversacion->id,
            'role' => 'user',
            'content' => 'Por favor reprogramar para el próximo jueves a la misma hora',
        ]);

        $respuesta = BotService::fromUser($this->doctora)
            ->forConversation($conversacion)
            ->reply($conversacion);

        $texto = is_array($respuesta) ? ($respuesta['text'] ?? json_encode($respuesta)) : (string) $respuesta;

        fwrite(STDERR, PHP_EOL.'--- LORE RESPONDIÓ ---'.PHP_EOL.$texto.PHP_EOL);

        $cita->refresh();
        fwrite(STDERR, '--- CITA: '.$cita->starts_at->format('Y-m-d H:i').' (esperada '.$siguiente->format('Y-m-d').' 15:00) ---'.PHP_EOL);

        $this->assertSame(
            $siguiente->format('Y-m-d').' 15:00:00',
            $cita->starts_at->format('Y-m-d H:i:s'),
            'Lore tenía que MOVER la cita: la paciente ya dijo el día y la hora, y estaban libres. '
            .'Si sigue en la fecha vieja es que volvió a preguntar, que es el fallo que se corrigió.'
        );

        $this->assertStringNotContainsString('¿Te confirmo', $texto);
    }
}
