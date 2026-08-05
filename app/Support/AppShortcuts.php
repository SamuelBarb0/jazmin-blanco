<?php

namespace App\Support;

/**
 * Secciones que pueden fijarse como acceso directo en la pantalla de inicio.
 *
 * Existe porque el manifiesto se genera por página: iOS abre el acceso directo
 * en el `start_url` del manifiesto, no en la página donde lo creaste, así que
 * con un manifiesto estático TODOS los iconos terminaban en el mismo sitio.
 *
 * La lista es blanca a propósito: el `start_url` viaja en la URL del
 * manifiesto y no queremos que cualquier ruta arbitraria acabe dentro.
 */
class AppShortcuts
{
    /**
     * Primer segmento de la ruta => nombre que verá la doctora bajo el icono.
     *
     * Los rótulos son los mismos del menú lateral: si en el menú dice
     * "Conversaciones", el icono no puede decir "Inbox".
     *
     * @var array<string,string>
     */
    private const SECCIONES = [
        'dashboard' => 'Centro de comando',
        'inbox' => 'Conversaciones',
        'pipeline' => 'Pipeline',
        'leads' => 'Pacientes',
        'appointments' => 'Agenda',
        'asistente' => 'Asistente',
        'campaigns' => 'Campañas',
        'services' => 'Servicios',
        'knowledge' => 'Conocimiento',
    ];

    /**
     * Sección válida a partir de una ruta cualquiera, o null si no lo es.
     *
     * Se queda con el primer segmento: estando en `/leads/42/editar` el acceso
     * directo debe llevar al listado de Pacientes, no a esa ficha concreta.
     */
    public static function seccion(?string $path): ?string
    {
        $primero = trim(explode('/', trim((string) $path, '/'))[0] ?? '');

        return array_key_exists($primero, self::SECCIONES) ? $primero : null;
    }

    /**
     * Nombre de la sección; cae en el dashboard si la ruta no es fijable.
     */
    public static function etiqueta(?string $path): string
    {
        return self::SECCIONES[self::seccion($path) ?? 'dashboard'];
    }

    /**
     * Ruta absoluta a la que debe abrir el acceso directo.
     */
    public static function inicio(?string $path): string
    {
        return '/'.(self::seccion($path) ?? 'dashboard');
    }
}
