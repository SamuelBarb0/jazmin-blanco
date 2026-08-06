<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La pantalla de equipo.
 *
 * Existe para que dar y quitar accesos no dependa del proveedor: hasta ahora
 * cada acceso nuevo era una intervención a mano en la base de datos.
 */
class EquipoTest extends TestCase
{
    use RefreshDatabase;

    private User $doctora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctora = User::factory()->create(['name' => 'Dra. Jasmin Blanco']);
    }

    private function invitado(array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'cuenta_id' => $this->doctora->id,
            'es_propietario' => false,
        ], $extra));
    }

    public function test_la_duena_ve_su_equipo(): void
    {
        $this->invitado(['name' => 'Ingeniero invitado']);

        $this->actingAs($this->doctora)
            ->get(route('equipo.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('miembros', 2));
    }

    /** Repartir accesos a las historias de las pacientes no es de cualquiera. */
    public function test_un_invitado_no_entra_a_la_pantalla_de_equipo(): void
    {
        $this->actingAs($this->invitado())
            ->get(route('equipo.index'))
            ->assertForbidden();
    }

    public function test_la_duena_crea_un_acceso_y_entra_a_su_clinica(): void
    {
        $this->actingAs($this->doctora)
            ->post(route('equipo.store'), [
                'name' => 'Sebastián Rivera',
                'email' => 'sebasrivera2.1@gmail.com',
                'password' => 'clave-de-prueba-1',
            ])
            ->assertRedirect();

        $nuevo = User::where('email', 'sebasrivera2.1@gmail.com')->firstOrFail();

        // Lo que lo distingue de crear un usuario suelto: entra a la clínica
        // de quien lo invita, no a una vacía suya.
        $this->assertSame($this->doctora->id, $nuevo->cuenta_id);
        $this->assertFalse($nuevo->es_propietario);
        $this->assertTrue($nuevo->activo);
    }

    /** Sin contraseña se genera una y se muestra UNA vez. */
    public function test_sin_contrasena_se_genera_y_se_devuelve_para_entregarla(): void
    {
        $this->actingAs($this->doctora)
            ->post(route('equipo.store'), ['name' => 'Alguien', 'email' => 'alguien@clinica.test'])
            ->assertSessionHas('clave_generada', fn ($clave) => is_string($clave) && strlen($clave) >= 12);
    }

    public function test_no_se_repite_un_correo_ya_registrado(): void
    {
        $this->actingAs($this->doctora)
            ->post(route('equipo.store'), ['name' => 'Otro', 'email' => $this->doctora->email])
            ->assertSessionHasErrors('email');
    }

    /**
     * Quitar el acceso no borra a la persona: si se borrara, se perdería el
     * rastro de lo que hizo mientras lo tuvo.
     */
    public function test_quitar_el_acceso_impide_entrar_pero_conserva_la_cuenta(): void
    {
        $invitado = $this->invitado(['email' => 'invitado@clinica.test']);

        $this->actingAs($this->doctora)
            ->patch(route('equipo.toggle', $invitado))
            ->assertRedirect();

        $this->assertFalse($invitado->refresh()->activo);
        $this->assertDatabaseHas('users', ['id' => $invitado->id]);
    }

    /**
     * La otra mitad: sin acceso, la contraseña correcta deja de servir.
     *
     * Va en su propia prueba y sin `actingAs` porque el middleware de invitado
     * intercepta el POST a /login cuando ya hay sesión, y entonces la prueba
     * pasaría sin haber comprobado nada.
     */
    public function test_una_cuenta_sin_acceso_no_puede_entrar_aunque_sepa_la_contrasena(): void
    {
        $this->invitado(['email' => 'invitado@clinica.test', 'activo' => false]);

        $this->post(route('login'), ['email' => 'invitado@clinica.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /** Y con acceso, entra normal: el freno no puede dejar a nadie fuera de más. */
    public function test_una_cuenta_activa_entra_sin_problema(): void
    {
        $this->invitado(['email' => 'activo@clinica.test', 'activo' => true]);

        $this->post(route('login'), ['email' => 'activo@clinica.test', 'password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticated();
    }

    public function test_devolver_el_acceso_lo_deja_entrar_otra_vez(): void
    {
        $invitado = $this->invitado(['activo' => false]);

        $this->actingAs($this->doctora)->patch(route('equipo.toggle', $invitado));

        $this->assertTrue($invitado->refresh()->activo);
    }

    /** Si la dueña pudiera quitarse el acceso, nadie podría devolvérselo. */
    public function test_la_duena_no_se_puede_desactivar(): void
    {
        $this->actingAs($this->doctora)
            ->patch(route('equipo.toggle', $this->doctora))
            ->assertForbidden();

        $this->assertTrue($this->doctora->refresh()->activo);
    }

    public function test_no_se_toca_el_equipo_de_otra_clinica(): void
    {
        $otraDoctora = User::factory()->create();
        $suInvitado = User::factory()->create(['cuenta_id' => $otraDoctora->id, 'es_propietario' => false]);

        $this->actingAs($this->doctora)
            ->patch(route('equipo.toggle', $suInvitado))
            ->assertNotFound();

        $this->assertTrue($suInvitado->refresh()->activo);
    }
}
