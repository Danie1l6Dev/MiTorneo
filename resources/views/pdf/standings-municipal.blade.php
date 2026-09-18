<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: {{ $letterhead === 'municipal' ? '190px' : '125px' }} 40px 60px 40px; }

        @include('pdf.partials.municipal-style')
    </style>
</head>
<body>
    @include("pdf.partials.{$letterhead}-header")

    <h1>Tabla de posiciones oficial</h1>
    <h2>{{ $tournament->name }}</h2>

    <div class="meta"><strong>Categoría:</strong> {{ $category->name }}</div>
    <div class="meta"><strong>Fase:</strong> {{ $phase->name }}</div>
    <div class="meta"><strong>Fecha de corte:</strong> {{ now()->locale('es')->translatedFormat('d \d\e F \d\e Y') }}</div>

    @include('pdf.partials.municipal-standings-tables')

    @include("pdf.partials.{$letterhead}-signature")
</body>
</html>
