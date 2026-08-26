<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El buscador de la bandeja (reporte de la doctora, 26-ago-2026: «sigo sin la
 * posibilidad de acceder al panel de números con buscador»).
 *
 * La lista solo se podía recorrer en orden de última actividad. Con 319 chats
 * en producción, dar con una paciente concreta era bajar a ciegas, así que la
 * doctora acababa buscándola en su celular y no en el CRM.
 *
 * Se busca por tres caminos porque los tres fallan por separado: el nombre que
 * trae WhatsApp no siempre es el de la historia clínica, el teléfono está
 * guardado de cualquier forma, y a veces lo único que se recuerda es una
 * palabra de lo que se habló.
 */
class BuscadorDeLaBandejaTest extends TestCase
{
    use RefreshDatabase;

    private User $doctora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctora = User::factory()->create();
    }

    private function chat(string $nombre, string $telefono, string $mensaje = 'Hola'): Conversation
    {
        $lead = Lead::create([
            'user_id' => $this->doctora->id,
            'name' => $nombre,
            'phone' => $telefono,
        ]);

        $conversacion = Conversation::create([
            'user_id' => $this->doctora->id,
            'lead_id' => $lead->id,
            'channel' => 'whatsapp',
            'title' => $nombre,
        ]);

        Message::create([
            'conversation_id' => $conversacion->id,
            'role' => 'user',
            'content' => $mensaje,
        ]);

        return $conversacion;
    }

    /** @param  array<string,mixed>  $parametros */
    private function buscar(array $parametros): TestResponse
    {
        return $this->actingAs($this->doctora)->get(route('inbox.index', $parametros));
    }

    public function test_sin_busqueda_salen_todas(): void
    {
        $this->chat('Marcela Ruiz', '573001112233');
        $this->chat('Yaneth Gómez', '573004445566');

        $this->buscar([])->assertOk()
            ->assertInertia(fn ($page) => $page->has('conversations', 2)->where('total', 2));
    }

    public function test_busca_por_nombre_sin_importar_tildes_ni_mayusculas(): void
    {
        $this->chat('Marcela Ruiz', '573001112233');
        $this->chat('Yaneth Gómez', '573004445566');

        $this->buscar(['q' => 'gomez'])->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('conversations', 1)
                ->where('conversations.0.title', 'Yaneth Gómez')
                // El total NO se filtra: es el «1 de 2» que dice cuánto se dejó fuera.
                ->where('total', 2));
    }

    /**
     * El caso que da nombre al reporte: buscar por el número.
     *
     * Se compara solo dígitos porque en producción el mismo teléfono está
     * guardado a 10 cifras, con el 57 delante y con espacios; un `like` sobre
     * la columna se perdería la mitad de las pacientes.
     */
    public function test_busca_por_telefono_escrito_de_cualquier_forma(): void
    {
        $this->chat('Marcela Ruiz', '3001112233');
        $this->chat('Yaneth Gómez', '573004445566');

        foreach (['3001112233', '573001112233', '300 111 2233', '2233'] as $tecleado) {
            $this->buscar(['q' => $tecleado])->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->has('conversations', 1)
                    ->where('conversations.0.title', 'Marcela Ruiz'));
        }
    }

    public function test_busca_dentro_de_los_mensajes(): void
    {
        $this->chat('Marcela Ruiz', '573001112233', 'Necesito reprogramar mi cita');
        $this->chat('Yaneth Gómez', '573004445566', 'Ya hice la transferencia');

        $this->buscar(['q' => 'transferencia'])->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('conversations', 1)
                ->where('conversations.0.title', 'Yaneth Gómez'));
    }

    /**
     * Buscando no se abre nada solo: el relleno de escritorio traería el chat
     * más reciente de la clínica, que casi nunca es el que se está buscando, y
     * taparía el resultado con otra conversación.
     */
    public function test_buscando_no_se_autoabre_otro_chat(): void
    {
        $this->chat('Marcela Ruiz', '573001112233');
        $this->chat('Yaneth Gómez', '573004445566');

        $this->buscar(['q' => 'marcela'])->assertOk()
            ->assertInertia(fn ($page) => $page->where('selected', null));

        // Sin buscar sí, que es lo que evita el panel vacío en pantalla grande.
        $this->buscar([])->assertOk()
            ->assertInertia(fn ($page) => $page->where('auto_selected', true));
    }

    public function test_una_busqueda_sin_resultados_devuelve_la_lista_vacia(): void
    {
        $this->chat('Marcela Ruiz', '573001112233');

        $this->buscar(['q' => 'no existe nadie así'])->assertOk()
            ->assertInertia(fn ($page) => $page->has('conversations', 0)->where('total', 1));
    }
}
