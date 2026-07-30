{{-- $empresa, $correo, $telefono y $actualizado los pasa LegalController. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titulo') · {{ $empresa['clinic_name'] }}</title>
    <meta name="description" content="@yield('titulo') de {{ $empresa['clinic_name'] }}.">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-serif:400|inter:400,500,600&display=swap" rel="stylesheet">
    <style>
        :root {
            --tinta: #16243f;
            --tinta-suave: #4a5568;
            --azul: #1d6ff2;
            --linea: rgba(22, 36, 63, .12);
            --fondo: #f7f9fc;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Inter', system-ui, sans-serif;
            color: var(--tinta);
            background: var(--fondo);
            line-height: 1.7;
        }
        .barra {
            background: var(--tinta);
            color: #fff;
            padding: 1.1rem 1.5rem;
        }
        .barra .interior {
            max-width: 820px;
            margin: 0 auto;
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .barra .nombre { font-family: 'Instrument Serif', Georgia, serif; font-size: 1.35rem; }
        .barra a { color: rgba(255, 255, 255, .8); text-decoration: none; font-size: .9rem; }
        .barra a:hover { color: #fff; }

        main {
            max-width: 820px;
            margin: 0 auto;
            padding: 3rem 1.5rem 5rem;
        }
        h1 {
            font-family: 'Instrument Serif', Georgia, serif;
            font-size: clamp(2rem, 5vw, 2.8rem);
            font-weight: 400;
            margin: 0 0 .4rem;
        }
        .actualizado { color: var(--tinta-suave); font-size: .9rem; margin-bottom: 2.5rem; }
        h2 {
            font-size: 1.15rem;
            margin: 2.6rem 0 .8rem;
            padding-top: 1.6rem;
            border-top: 1px solid var(--linea);
        }
        h2:first-of-type { border-top: 0; padding-top: 0; }
        h3 { font-size: 1rem; margin: 1.6rem 0 .5rem; }
        p, li { color: var(--tinta-suave); }
        li { margin-bottom: .4rem; }
        strong { color: var(--tinta); font-weight: 600; }
        a { color: var(--azul); }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: .93rem; }
        th, td { text-align: left; padding: .7rem .8rem; border-bottom: 1px solid var(--linea); vertical-align: top; }
        th { color: var(--tinta); font-weight: 600; background: rgba(22, 36, 63, .03); }
        .aviso {
            background: #fff;
            border: 1px solid var(--linea);
            border-left: 3px solid var(--azul);
            border-radius: 10px;
            padding: 1rem 1.2rem;
            margin: 1.5rem 0;
        }
        .aviso p:last-child { margin-bottom: 0; }
        .aviso p:first-child { margin-top: 0; }
        footer {
            border-top: 1px solid var(--linea);
            margin-top: 3.5rem;
            padding-top: 1.5rem;
            font-size: .87rem;
            color: var(--tinta-suave);
        }
        footer a { margin-right: 1.2rem; }
        .tabla-scroll { overflow-x: auto; }
    </style>
</head>
<body>
    <div class="barra">
        <div class="interior">
            <span class="nombre">{{ $empresa['clinic_name'] }}</span>
            @if($empresa['clinic_landing'])
                <a href="{{ $empresa['clinic_landing'] }}">Volver al sitio</a>
            @endif
        </div>
    </div>

    <main>
        <h1>@yield('titulo')</h1>
        <p class="actualizado">Última actualización: {{ $actualizado }}</p>

        @yield('contenido')

        <footer>
            <a href="{{ route('legal.privacidad') }}">Política de privacidad</a>
            <a href="{{ route('legal.terminos') }}">Condiciones del servicio</a>
            <a href="{{ route('legal.eliminacion') }}">Eliminación de datos</a>
            <p>{{ $empresa['clinic_name'] }}@if($empresa['clinic_address']) · {{ $empresa['clinic_address'] }}@endif</p>
        </footer>
    </main>
</body>
</html>
