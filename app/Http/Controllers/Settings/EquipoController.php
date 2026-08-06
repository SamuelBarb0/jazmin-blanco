<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Quién más puede entrar al CRM de la clínica.
 *
 * Existe para que dar y quitar accesos no dependa del proveedor: hasta ahora
 * cada acceso nuevo era una intervención en la base de datos.
 */
class EquipoController extends Controller
{
    private function autorizar(Request $request): void
    {
        // Solo la dueña. Repartir accesos a las historias de las pacientes no
        // es una decisión que pueda tomar cualquiera que tenga cuenta.
        abort_unless($request->user()->puedeAdministrarEquipo(), 403);
    }

    public function index(Request $request): Response
    {
        $this->autorizar($request);

        return Inertia::render('settings/equipo', [
            'miembros' => $request->user()->equipo()
                ->orderByDesc('es_propietario')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'es_propietario', 'activo', 'created_at']),
            'yo' => $request->user()->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->autorizar($request);

        $datos = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            // Se puede dejar vacía: entonces se genera una y se muestra UNA vez
            // en pantalla, para que la clínica la entregue por donde quiera.
            'password' => ['nullable', 'string', Password::min(8)],
        ], [
            'email.unique' => 'Ya hay una cuenta con ese correo.',
        ]);

        $clave = $datos['password'] ?? Str::password(14);

        $miembro = User::create([
            'name' => $datos['name'],
            'email' => $datos['email'],
            'password' => Hash::make($clave),
            // Entra a la clínica de quien lo invita, no a una suya: esa es toda
            // la diferencia con crear un usuario a mano.
            'cuenta_id' => $request->user()->cuenta_id,
            'es_propietario' => false,
            'activo' => true,
        ]);

        $miembro->forceFill(['email_verified_at' => now()])->save();

        return back()->with('success', "Acceso creado para {$miembro->name}.")
            ->with('clave_generada', empty($datos['password']) ? $clave : null);
    }

    public function toggle(Request $request, User $miembro): RedirectResponse
    {
        $this->autorizar($request);
        $this->debeSerDeMiClinica($request, $miembro);

        // La dueña no puede quitarse el acceso a sí misma: la clínica se
        // quedaría sin nadie que pueda volver a darlo.
        abort_if($miembro->es_propietario, 403, 'La cuenta dueña no se puede desactivar.');

        $miembro->forceFill(['activo' => ! $miembro->activo])->save();

        return back()->with('success', $miembro->activo
            ? "{$miembro->name} vuelve a tener acceso."
            : "{$miembro->name} ya no puede entrar.");
    }

    public function destroy(Request $request, User $miembro): RedirectResponse
    {
        $this->autorizar($request);
        $this->debeSerDeMiClinica($request, $miembro);

        abort_if($miembro->es_propietario, 403, 'La cuenta dueña no se puede eliminar.');

        $nombre = $miembro->name;
        $miembro->delete();

        return back()->with('success', "Se eliminó la cuenta de {$nombre}.");
    }

    /** Nadie toca el equipo de otra clínica. */
    private function debeSerDeMiClinica(Request $request, User $miembro): void
    {
        abort_if($miembro->cuenta_id !== $request->user()->cuenta_id, 404);
    }
}
