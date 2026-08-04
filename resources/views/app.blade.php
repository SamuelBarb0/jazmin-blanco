<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        {{-- viewport-fit=cover deja que el contenido llegue bajo la barra del iPhone;
             el CSS lo compensa con safe-area-inset. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        {{-- El ?v evita que el navegador siga mostrando el favicon anterior en caché --}}
        <link rel="icon" href="/favicon.ico?v=aurum" sizes="any">

        {{-- iOS NO respeta la transparencia en el icono de inicio: lo que sea
             transparente lo pinta de negro. Por eso apple-touch-icon.png va con
             fondo blanco sólido, no el PNG recortado. --}}
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=aurum">
        <meta name="apple-mobile-web-app-title" content="Aurum">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="theme-color" content="#0b1220">
        <link rel="manifest" href="/site.webmanifest">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700|instrument-serif:400,400i" rel="stylesheet" />

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
