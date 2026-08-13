<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * La paciente que elige transferencia tiene que quedarse con TODAS las cuentas.
 *
 * El modelo se salta los datos de pago aunque se le pidan de mil formas (está
 * documentado en `conDatosDePago()`), así que el código los anexa. La red de
 * seguridad se conformaba con encontrar UN número largo en la respuesta y daba
 * por copiado todo el bloque. Con una sola cuenta y el Nequi casi siempre
 * acertaba; desde que la clínica tiene Davivienda, Bancolombia y Nequi, que el
 * modelo escriba solo el Nequi es un desenlace normal — y ahí la paciente se
 * quedaba con el cupo apartado y sin saber a dónde transferir.
 */
class DatosDePagoCompletosTest extends TestCase
{
    use RefreshDatabase;

    private const DATOS = "🏦 DAVIVIENDA\n- Cuenta de Ahorros: 0550108900811119\n\n"
        ."🏦 BANCOLOMBIA\n- Cuenta de Ahorros: 320000500\n\n"
        .'📱 NEQUI: 3165394709';

    public function test_si_el_modelo_solo_escribe_el_nequi_se_anexan_las_cuentas(): void
    {
        $texto = $this->respuesta('Listo, tu cita quedó apartada 💙 Puedes transferir al Nequi 3165394709.');

        $this->assertStringContainsString('0550108900811119', $texto, 'Falta la cuenta de Davivienda.');
        $this->assertStringContainsString('320000500', $texto, 'Falta la cuenta de Bancolombia.');
    }

    public function test_si_el_modelo_los_escribe_todos_no_se_repite_nada(): void
    {
        $texto = $this->respuesta(
            "Tu cita quedó apartada 💙\nDavivienda 0550108900811119\nBancolombia 320000500\nNequi 3165394709",
        );

        foreach (['0550108900811119', '320000500', '3165394709'] as $numero) {
            $this->assertSame(1, substr_count($texto, $numero), "El número {$numero} salió repetido.");
        }
    }

    public function test_si_el_modelo_no_escribe_ninguno_se_anexa_el_bloque_entero(): void
    {
        $texto = $this->respuesta('Tu cita quedó apartada 💙 Recuerda hacer la transferencia.');

        foreach (['0550108900811119', '320000500', '3165394709'] as $numero) {
            $this->assertStringContainsString($numero, $texto);
        }
    }

    public function test_sin_datos_configurados_no_se_anexa_nada(): void
    {
        $bot = BotService::fromUser(User::factory()->create());
        $ref = new ReflectionClass(BotService::class);

        $metodo = $ref->getMethod('conDatosDePago');
        $metodo->setAccessible(true);

        $original = 'Tu cita quedó apartada 💙';

        $this->assertSame($original, $metodo->invoke($bot, $original));
    }

    /**
     * Pasa el texto por la red de seguridad con los datos de pago pendientes,
     * que es exactamente el estado en el que queda tras apartar por transferencia.
     */
    private function respuesta(string $textoDelModelo): string
    {
        $bot = BotService::fromUser(User::factory()->create());
        $ref = new ReflectionClass(BotService::class);

        $pendiente = $ref->getProperty('datosPagoPorAnexar');
        $pendiente->setAccessible(true);
        $pendiente->setValue($bot, self::DATOS);

        $metodo = $ref->getMethod('conDatosDePago');
        $metodo->setAccessible(true);

        return $metodo->invoke($bot, $textoDelModelo);
    }
}
