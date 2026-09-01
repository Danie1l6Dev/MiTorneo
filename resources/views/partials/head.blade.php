<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])

{{-- MiTorneo usa siempre el tema oscuro (identidad de estadio nocturno): se fuerza
     aqui, antes de que el script de apariencia de Flux (mas abajo) detecte el modo
     del sistema, para que la app nunca cambie a claro sin importar la preferencia del SO. --}}
<script>window.localStorage.setItem('flux.appearance', 'dark');</script>
@fluxAppearance
