{{--
    Needs: MatchResultsReportService::matchReport() + the letterhead vars
    (PdfLetterheadService). Every optional line (round, group, date,
    referee, statistics, rosters, sanctions...) is only printed when this
    match actually has it recorded.
--}}
@php
    $types = \App\Enums\MatchEventType::cases();
    $showRosters = collect($rosters)->contains(fn ($roster) => $roster['players'] !== [] || $roster['coach'] !== null);
    $sanctions = $match->sanctions;
    $showFechas = $sanctions->contains(fn ($sanction) => $sanction->matches_banned !== null);
    $showFine = $sanctions->contains(fn ($sanction) => $sanction->fine_amount !== null);
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

    <h1>Informe del partido</h1>
    <h2>{{ $match->tournament->name }}</h2>

    <div class="meta"><strong>Categoría:</strong> {{ $match->category->name }}</div>
    <div class="meta"><strong>Fase:</strong> {{ $match->competitionPhase->name }}</div>
    @if ($roundLabel)
        <div class="meta"><strong>Ronda:</strong> {{ $roundLabel }}</div>
    @endif
    @if ($match->group)
        <div class="meta"><strong>Grupo:</strong> {{ $match->group->name }}</div>
    @endif
    @if ($match->scheduled_at)
        <div class="meta"><strong>Fecha y hora:</strong> {{ $match->scheduled_at->locale('es')->translatedFormat($match->hasKickoffTime() ? 'l d \d\e F \d\e Y, h:i A' : 'l d \d\e F \d\e Y') }}</div>
    @endif
    @if ($match->venue)
        <div class="meta"><strong>Lugar:</strong> {{ $match->venue->name }}</div>
    @endif
    @if ($match->referee)
        <div class="meta"><strong>Árbitro:</strong> {{ $match->referee->full_name }}</div>
    @endif
    <div class="meta"><strong>Estado:</strong> {{ $match->status->label() }}</div>

    <table class="scoreboard">
        <tr>
            <td class="team team-home">
                <span class="side-label">Local</span>
                {{ $match->homeTeam?->name ?? 'Por definir' }}
            </td>
            <td class="score">{{ $score }}</td>
            <td class="team team-away">
                <span class="side-label">Visitante</span>
                {{ $match->awayTeam?->name ?? 'Por definir' }}
            </td>
        </tr>
    </table>

    @if ($firstLegScore)
        <div class="score-details">Partido de ida: {{ $firstLegScore }}</div>
    @endif
    @foreach ($details as $detail)
        <div class="score-details">{{ $detail }}</div>
    @endforeach

    @if ($statistics !== [])
        <div class="report-section-title">Estadísticas</div>

        <table class="results">
            <thead>
                <tr>
                    <th style="width: 35%;">{{ $match->homeTeam->name }}</th>
                    <th>Estadística</th>
                    <th style="width: 35%;">{{ $match->awayTeam->name }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($statistics as $statistic)
                    <tr>
                        <td class="center">{{ $statistic['home'] }}</td>
                        <td class="center">{{ $statistic['label'] }}</td>
                        <td class="center">{{ $statistic['away'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($events)
        <div class="report-section-title">Eventos del partido</div>

        <table class="results">
            <thead>
                <tr>
                    <th style="width: 50%;">{{ $match->homeTeam->name }}</th>
                    <th style="width: 50%;">{{ $match->awayTeam->name }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="events events-home">
                        @foreach ($events['home'] as $subject)
                            <div>{{ $subject }}</div>
                        @endforeach
                    </td>
                    <td class="events events-away">
                        @foreach ($events['away'] as $subject)
                            <div>{{ $subject }}</div>
                        @endforeach
                    </td>
                </tr>
            </tbody>
        </table>
    @endif

    @if ($showRosters)
        {{-- Keeps the heading from being stranded at the bottom of a page, away from its tables. --}}
        <div style="page-break-inside: avoid;">
        <div class="report-section-title">Planteles</div>

        <table class="rosters">
            <tbody>
                <tr>
                    @foreach (array_values($rosters) as $index => $roster)
                        @if ($index > 0)
                            <td class="gap"></td>
                        @endif
                        <td>
                            <table class="roster">
                                <caption>{{ $roster['team']->name }}</caption>
                                <thead>
                                    <tr>
                                        @if ($roster['hasJerseys'])
                                            <th>#</th>
                                        @endif
                                        <th>Jugador</th>
                                        @if ($hasEvents)
                                            @foreach ($types as $type)
                                                <th>{{ $type->shortLabel() }}</th>
                                            @endforeach
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($roster['players'] as $player)
                                        <tr>
                                            @if ($roster['hasJerseys'])
                                                <td class="num">{{ $player['jersey'] }}</td>
                                            @endif
                                            <td>{{ $player['name'] }}</td>
                                            @if ($hasEvents)
                                                @foreach ($types as $type)
                                                    <td class="num">{{ $player['counts'][$type->value] ?: '' }}</td>
                                                @endforeach
                                            @endif
                                        </tr>
                                    @endforeach
                                    @if ($roster['coach'])
                                        <tr class="coach">
                                            @if ($roster['hasJerseys'])
                                                <td class="num">DT</td>
                                                <td>{{ $roster['coach']['name'] }}</td>
                                            @else
                                                <td>DT: {{ $roster['coach']['name'] }}</td>
                                            @endif
                                            @if ($hasEvents)
                                                @foreach ($types as $type)
                                                    <td class="num">{{ $roster['coach']['counts'][$type->value] ?: '' }}</td>
                                                @endforeach
                                            @endif
                                        </tr>
                                    @endif
                                </tbody>
                            </table>
                        </td>
                    @endforeach
                </tr>
            </tbody>
        </table>
        </div>
    @endif

    @if ($sanctions->isNotEmpty())
        <div class="report-section-title">Sanciones originadas en el partido</div>

        <table class="results">
            <thead>
                <tr>
                    <th>Sancionado</th>
                    <th>Equipo</th>
                    <th>Tipo</th>
                    <th>Estado</th>
                    @if ($showFechas)
                        <th>Fechas</th>
                    @endif
                    @if ($showFine)
                        <th>Multa</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($sanctions as $sanction)
                    <tr>
                        <td>{{ $sanction->subjectLabel() }}</td>
                        <td>{{ $sanction->team?->name }}</td>
                        <td class="center">{{ $sanction->type->label() }}</td>
                        <td class="center">{{ $sanction->stateLabel() }}</td>
                        @if ($showFechas)
                            <td class="center">{{ $sanction->matches_banned ?? '—' }}</td>
                        @endif
                        @if ($showFine)
                            <td class="center">{{ $sanction->fine_amount !== null ? '$'.number_format($sanction->fine_amount, 0, ',', '.') : '—' }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($hasEvents)
        <div class="legend"><strong>Convenciones:</strong> G = gol · A = asistencia · TA = tarjeta amarilla · TR = tarjeta roja · DT = director técnico</div>
    @endif

    @include("pdf.partials.{$letterhead}-signature")
</body>
</html>
