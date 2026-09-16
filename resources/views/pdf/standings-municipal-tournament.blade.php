<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 190px 40px 60px 40px; }

        @include('pdf.partials.municipal-style')
    </style>
</head>
<body>
    @include('pdf.partials.municipal-header')

    <h1>Tabla de posiciones oficial</h1>
    <h2>{{ $tournament->name }}</h2>

    <div class="meta"><strong>Fecha de corte:</strong> {{ now()->locale('es')->translatedFormat('d \d\e F \d\e Y') }}</div>

    @foreach ($sections as $section)
        <div class="category-section">
            <h3>{{ $section['category']->name }}</h3>

            @foreach ($section['phases'] as $phaseEntry)
                <div class="meta"><strong>Fase:</strong> {{ $phaseEntry['phase']->name }}</div>

                @include('pdf.partials.municipal-standings-tables', ['tables' => $phaseEntry['tables']])
            @endforeach
        </div>
    @endforeach

    @include('pdf.partials.municipal-signature')
</body>
</html>
