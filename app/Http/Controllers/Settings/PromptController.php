<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\BotService;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Editor del system prompt de Lore.
 *
 * Deja que la dueña del consultorio cambie las instrucciones que gobiernan cómo
 * responde la asistente, sin pasar por el proveedor. Solo la dueña: tocar estas
 * reglas (pago, cumplimiento sanitario, identidad) cambia el comportamiento con
 * TODAS las pacientes, así que no es una decisión de cualquiera con cuenta.
 *
 * Lo que se guarda es una PLANTILLA con etiquetas {{...}} que el código rellena
 * con los datos vivos (valor de la valoración, horarios, base de conocimiento,
 * agenda del día, contexto de la campaña). Si la doctora borra una etiqueta, ese
 * dato deja de entrar al prompt — por eso la pantalla avisa y permite restaurar
 * el original.
 */
class PromptController extends Controller
{
    /**
     * Las etiquetas que la plantilla puede usar, con una explicación para la
     * doctora. El orden es el que se muestra en el panel.
     */
    private const TOKENS = [
        '{{quien_es}}' => 'El nombre de la asistente y de la doctora ("Lore, la asistente de la Dra. ...").',
        '{{doctora}}' => 'El nombre de la doctora.',
        '{{clinica}}' => 'El nombre del consultorio.',
        '{{direccion}}' => 'La dirección de la sede.',
        '{{horarios}}' => 'Los horarios de atención.',
        '{{formas_pago}}' => 'Las formas de pago configuradas.',
        '{{valor_valoracion}}' => 'El valor de la valoración (se toma de Pagos).',
        '{{base_conocimiento}}' => 'TODA la información de servicios que escribiste. Sin esta etiqueta, Lore se queda sin saber de los tratamientos.',
        '{{bloque_agenda}}' => 'Las instrucciones para agendar y la fecha de hoy (solo si la agenda está conectada).',
        '{{indicaciones_doctora}}' => 'Tus "indicaciones adicionales" del perfil del bot.',
        '{{marca_equipo}}' => 'La marca interna de los mensajes que el equipo escribe a mano.',
        '{{linea_landing}}' => 'La línea con tu página web, si la configuraste.',
        '{{linea_link_valoracion}}' => 'La línea con el link de pago en línea, si aplica.',
        '{{lineas_prioridad_pago}}' => 'Las reglas de cómo ofrecer el pago (link vs. transferencia).',
        '{{presentacion}}' => 'Cómo se presenta según si es el primer contacto o ya venían hablando.',
        '{{bloque_citas}}' => 'Las citas que la paciente ya tiene agendadas.',
        '{{bloque_campana}}' => 'El anuncio del que viene la paciente, si aplica.',
    ];

    private function autorizar(Request $request): void
    {
        abort_unless($request->user()->puedeAdministrarEquipo(), 403);
    }

    public function edit(Request $request): Response
    {
        $this->autorizar($request);

        $override = Settings::botPromptOverride();
        $default = BotService::defaultPromptTemplate();

        return Inertia::render('settings/prompt', [
            'prompt' => $override ?? $default,
            'default' => $default,
            'personalizado' => filled($override),
            'tokens' => collect(self::TOKENS)
                ->map(fn ($desc, $etiqueta) => ['etiqueta' => $etiqueta, 'descripcion' => $desc])
                ->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->autorizar($request);

        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:60000'],
        ]);

        Settings::setBotPromptOverride($data['prompt']);

        return back()->with('success', 'Instrucciones de Lore guardadas. Los cambios aplican de inmediato.');
    }

    public function reset(Request $request): RedirectResponse
    {
        $this->autorizar($request);

        Settings::setBotPromptOverride(null);

        return back()->with('success', 'Se restauró el texto original de Lore.');
    }
}
