{{--
    Needs: $tournament, $meta (label => value), $phases (list of
    ['heading' => ?string, 'sections' => MatchResultsReportService::phaseSections()]),
    plus the letterhead vars (PdfLetterheadService).

    Each match is its own small table; its date and referee go on a
    centered line underneath, only the parts that match actually has.
--}}
@php
    $allRows = collect($phases)
        ->flatMap(fn ($entry) => $entry['sections'])
        ->flatMap(fn ($section) => $section['blocks'])
        ->flatMap(fn ($block) => $block['rows']);

    $hasEvents = $allRows->contains(fn ($row) => $row['events'] !== null);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: {{ $letterhead === 'municipal' ? '190px' : '125px' }} 40px 60px 40px; }

        @include('pdf.partials.municipal-style')
        @include('pdf.partials.results-style')
    </style>
</head>
<body>
    @include("pdf.partials.{$letterhead}-header")

    <h1>Resultados de partidos</h1>
    <h2>{{ $tournament->name }}</h2>

    @foreach ($meta as $label => $value)
        <div class="meta"><strong>{{ $label }}:</strong> {{ $value }}</div>
    @endforeach
    <div class="meta"><strong>Fecha de corte:</strong> {{ now()->locale('es')->translatedFormat('d \d\e F \d\e Y') }}</div>

    @if ($allRows->isEmpty())
        <div class="empty-message">Todavía no hay partidos jugados para exportar.</div>
    @endif

    @foreach ($phases as $entry)
        <div class="phase-section">
            @if ($entry['heading'])
                <h3 style="margin-top: 14px;">Fase: {{ $entry['heading'] }}</h3>
            @endif

            @foreach ($entry['sections'] as $section)
                <div class="round-section">
                    <div class="round-title">
                        {{ $section['title'] }}
                        @if ($section['subtitle'])
                            <span class="subtitle">— {{ $section['subtitle'] }}</span>
                        @endif
                    </div>

                    @foreach ($section['blocks'] as $block)
                        @if ($block['label'])
                            <div class="block-label">{{ $block['label'] }}</div>
                        @endif

                        {{-- One table per match, so each one reads as its own unit
                             and never splits across pages. --}}
                        @foreach ($block['rows'] as $row)
                            @php
                                $match = $row['match'];
                                $info = array_filter([
                                    $match->scheduled_at ? 'Fecha: '.$match->scheduled_at->format('d/m/Y h:i A') : null,
                                    $match->venue ? 'Lugar: '.$match->venue : null,
                                    $match->referee ? 'Árbitro: '.$match->referee->full_name : null,
                                ]);
                            @endphp
                            <table class="results match-table">
                                <tbody>
                                    <tr>
                                        <td class="team-home" style="width: 43%;">{{ $match->homeTeam?->name ?? 'Por definir' }}</td>
                                        <td class="score" style="width: 14%;">
                                            {{ $row['score'] }}
                                            @if ($row['note'])
                                                <span class="small">{{ $row['note'] }}</span>
                                            @endif
                                            @foreach ($row['details'] as $detail)
                                                <span class="small">{{ $detail }}</span>
                                            @endforeach
                                        </td>
                                        <td class="team-away" style="width: 43%;">{{ $match->awayTeam?->name ?? 'Por definir' }}</td>
                                    </tr>
                                    {{-- Each side's events sit under that team's own column. --}}
                                    @if ($row['events'])
                                        <tr>
                                            <td class="events events-home">
                                                @foreach ($row['events']['home'] as $subject)
                                                    <div>{{ $subject }}</div>
                                                @endforeach
                                            </td>
                                            <td class="events"></td>
                                            <td class="events events-away">
                                                @foreach ($row['events']['away'] as $subject)
                                                    <div>{{ $subject }}</div>
                                                @endforeach
                                            </td>
                                        </tr>
                                    @endif
                                    @if ($info !== [])
                                        <tr>
                                            <td colspan="3" class="info-row">{{ implode(' · ', $info) }}</td>
                                        </tr>
                                    @endif
                                </tbody>
                            </table>
                        @endforeach

                        @if ($block['resting'])
                            <div class="resting">Descansa: {{ $block['resting'] }}</div>
                        @endif
                    @endforeach
                </div>
            @endforeach
        </div>
    @endforeach

    @if ($hasEvents)
        <div class="legend"><strong>Convenciones:</strong> G = gol · A = asistencia · TA = tarjeta amarilla · TR = tarjeta roja · DT = director técnico</div>
    @endif

    @include("pdf.partials.{$letterhead}-signature")
</body>
</html>
