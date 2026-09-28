<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Services\WhatsAppService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Envío de documentos desde la bandeja (etapa 3 del ajuste de Lore).
 *
 * Lo que importa aquí no es que "se envíe algo", sino que llegue como
 * DOCUMENTO y con el nombre real: un PDF mandado como imagen lo rechaza Meta,
 * y sin `filename` la paciente recibe el hash con el que se guardó en disco.
 */
class EnviarDocumentosTest extends TestCase
{
    use RefreshDatabase;

    private function conversacion(): Conversation
    {
        $usuario = User::factory()->create();

        Settings::put('whatsapp_token', 'token-de-prueba');
        Settings::put('whatsapp_phone_id', '111111111111111');

        // Sin factories en este proyecto: los modelos se crean a mano, como en
        // el resto de las pruebas.
        $lead = Lead::create([
            'user_id' => $usuario->id,
            'name' => 'Paciente de prueba',
            'phone' => '573001112233',
        ]);

        $conv = Conversation::create([
            'user_id' => $usuario->id,
            'lead_id' => $lead->id,
            'channel' => 'whatsapp',
            'title' => 'Paciente de prueba',
        ]);

        // La ventana de 24 h tiene que estar abierta o no deja escribir texto libre.
        $conv->messages()->create(['role' => 'user', 'content' => 'Hola', 'sent_by' => 'patient']);

        $this->actingAs($usuario);

        return $conv;
    }

    public function test_un_pdf_se_envia_como_documento_y_con_su_nombre_real()
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        $conv = $this->conversacion();

        $this->post("/inbox/{$conv->id}/send", [
            'content' => 'Te dejo tu plan',
            'archivo' => UploadedFile::fake()->create('Plan nutricional.pdf', 200, 'application/pdf'),
        ])->assertRedirect();

        Http::assertSent(function ($peticion) {
            $cuerpo = $peticion->data();

            return ($cuerpo['type'] ?? null) === 'document'
                // El nombre ORIGINAL, no el hash del disco.
                && ($cuerpo['document']['filename'] ?? null) === 'Plan nutricional.pdf'
                // El texto viaja de pie, para que llegue un solo mensaje.
                && ($cuerpo['document']['caption'] ?? null) === 'Te dejo tu plan';
        });
    }

    public function test_el_documento_queda_en_el_historial_de_la_conversacion()
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        $conv = $this->conversacion();

        $this->post("/inbox/{$conv->id}/send", [
            'archivo' => UploadedFile::fake()->create('Historia clínica.docx', 120,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ])->assertRedirect();

        $mensaje = $conv->messages()->where('sent_by', 'human')->latest('id')->first();

        $this->assertSame('document', $mensaje->media[0]['type']);
        $this->assertSame('Historia clínica.docx', $mensaje->media[0]['filename']);

        // Sin texto, el registro dice QUÉ se mandó: si no, al retomar el hilo el
        // asistente lee un mensaje vacío y no sabe de qué habla la paciente.
        $this->assertStringContainsString('Historia clínica.docx', $mensaje->content);
    }

    public function test_una_imagen_sigue_viajando_como_imagen()
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        $conv = $this->conversacion();

        $this->post("/inbox/{$conv->id}/send", [
            'archivo' => UploadedFile::fake()->image('antes.jpg'),
        ])->assertRedirect();

        Http::assertSent(fn ($p) => ($p->data()['type'] ?? null) === 'image');
    }

    /**
     * Los topes son distintos por tipo. Aplicarle el de imagen a un PDF
     * rechazaría historias clínicas de 6 MB que WhatsApp acepta de sobra.
     */
    public function test_el_tope_del_documento_es_el_suyo_y_no_el_de_la_imagen()
    {
        $this->assertSame(5 * 1024 * 1024, WhatsAppService::limiteBytes('image'));
        $this->assertSame(16 * 1024 * 1024, WhatsAppService::limiteBytes('video'));
        $this->assertSame(100 * 1024 * 1024, WhatsAppService::limiteBytes('document'));
    }

    public function test_un_pdf_de_seis_megas_se_acepta()
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        $conv = $this->conversacion();

        // Con el tope viejo (5 MB de imagen) esto se rechazaba en la validación.
        $this->post("/inbox/{$conv->id}/send", [
            'archivo' => UploadedFile::fake()->create('estudio.pdf', 6 * 1024, 'application/pdf'),
        ])->assertSessionHasNoErrors();
    }

    public function test_no_se_aceptan_tipos_que_whatsapp_no_admite()
    {
        Storage::fake('public');
        $conv = $this->conversacion();

        $this->post("/inbox/{$conv->id}/send", [
            'archivo' => UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload'),
        ])->assertSessionHasErrors('archivo');
    }
}
