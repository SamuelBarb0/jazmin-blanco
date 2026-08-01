<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las dos credenciales de Mercado Pago conviven y un interruptor decide cuál
 * cobra. Lo que se protege aquí es que el modo prueba no se quede encendido por
 * accidente ni se cuele una credencial en la caja equivocada: cualquiera de las
 * dos cosas se traduce en cobros reales que nadie quería, o en cobros de
 * mentira que la doctora cree buenos.
 */
class PaymentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const LIVE = 'APP_USR-1111111111111111-010101-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-11111111';

    private const TEST = 'TEST-2222222222222222-020202-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb-22222222';

    public function test_guarda_los_dos_juegos_sin_que_uno_pise_al_otro(): void
    {
        $this->guardar([
            'access_token' => self::LIVE,
            'test_access_token' => self::TEST,
        ])->assertSessionHasNoErrors();

        $this->assertSame(self::LIVE, Settings::mpLiveAccessToken());
        $this->assertSame(self::TEST, Settings::mpTestAccessToken());
        // Sin pedir modo prueba, se sigue cobrando de verdad.
        $this->assertSame(self::LIVE, Settings::mpAccessToken());
        $this->assertFalse(Settings::mpTestMode());
    }

    public function test_el_modo_prueba_cambia_la_credencial_activa_y_vuelve_atras(): void
    {
        $this->guardar([
            'access_token' => self::LIVE,
            'test_access_token' => self::TEST,
            'test_mode' => true,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Settings::mpTestMode());
        $this->assertSame(self::TEST, Settings::mpAccessToken());

        // Apagarlo devuelve producción intacta: no hubo que volver a pegarla.
        $this->guardar(['test_mode' => false])->assertSessionHasNoErrors();

        $this->assertFalse(Settings::mpTestMode());
        $this->assertSame(self::LIVE, Settings::mpAccessToken());
    }

    public function test_no_se_puede_activar_el_modo_prueba_sin_credencial_de_prueba(): void
    {
        $this->guardar([
            'access_token' => self::LIVE,
            'test_mode' => true,
        ])->assertSessionHasErrors('test_access_token');

        $this->assertFalse(Settings::mpTestMode());
        // El formulario se rechaza entero: tampoco se guarda a medias lo demás.
        $this->assertNull(Settings::mpLiveAccessToken());
    }

    public function test_el_modo_prueba_ya_guardado_sigue_valiendo_al_reenviar_el_formulario_vacio(): void
    {
        $this->guardar(['test_access_token' => self::TEST, 'test_mode' => true]);

        // La pantalla nunca devuelve el token, así que al volver a guardar viaja
        // en blanco: eso no puede leerse como "ya no hay credencial de prueba".
        $this->guardar(['test_mode' => true])->assertSessionHasNoErrors();

        $this->assertTrue(Settings::mpTestMode());
        $this->assertSame(self::TEST, Settings::mpAccessToken());
    }

    public function test_quitar_las_credenciales_de_prueba_no_toca_produccion(): void
    {
        $this->guardar([
            'access_token' => self::LIVE,
            'test_access_token' => self::TEST,
            'test_mode' => true,
        ]);

        $this->actingAs($this->usuario())->delete('/settings/pagos/prueba');

        $this->assertNull(Settings::mpTestAccessToken());
        $this->assertFalse(Settings::mpTestMode());
        $this->assertSame(self::LIVE, Settings::mpAccessToken());
    }

    public function test_rechaza_una_credencial_de_prueba_en_el_campo_de_produccion(): void
    {
        $this->guardar(['access_token' => self::TEST])->assertSessionHasErrors('access_token');

        $this->assertNull(Settings::mpLiveAccessToken());
    }

    public function test_rechaza_una_credencial_de_produccion_en_el_campo_de_prueba(): void
    {
        $this->guardar(['test_access_token' => self::LIVE])->assertSessionHasErrors('test_access_token');

        $this->assertNull(Settings::mpTestAccessToken());
    }

    /**
     * Si alguien borra la credencial de prueba dejando la marca encendida en la
     * tabla, el modo prueba NO puede seguir activo: el bot se quedaría sin
     * pasarela y volvería a creerle a la paciente que ya pagó.
     */
    public function test_el_modo_prueba_no_sobrevive_sin_su_credencial(): void
    {
        Settings::setMercadoPago(self::LIVE, null);
        Settings::put('mp_test_mode', '1');

        $this->assertFalse(Settings::mpTestMode());
        $this->assertSame(self::LIVE, Settings::mpAccessToken());
    }

    /**
     * @param  array<string,mixed>  $datos
     */
    private function guardar(array $datos): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->usuario())
            ->from('/settings/pagos')
            ->put('/settings/pagos', $datos + ['valoracion_amount' => 75000]);
    }

    private function usuario(): User
    {
        return User::factory()->create();
    }
}
