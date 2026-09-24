{{--
    Needs: $tournament, $category, $tables (list of ['type' => MatchEventType]
    + CompetitionStatisticsService::phaseBreakdown()), plus the letterhead
    vars (PdfLetterheadService).

    One table per statistic (each on its own page): # | Jugador | Equipo |
    [Grupo] | one column per phase | Total. The Grupo column only exists when
    some listed team belongs to a group. Long tables split across pages
    (dompdf repeats their header row) instead of being pushed whole onto the
    next page.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        {{-- Extra top room, like the programming sheet: a long table can
             continue at the very top of a page, right under the letterhead. --}}
        @page { margin: {{ $letterhead === 'municipal' ? '205px' : '135px' }} 40px 60px 40px; }

        @include('pdf.partials.municipal-style')

        @if ($letterhead === 'municipal')
            header { top: -200px; }
        @else
            header.default { top: -110px; }
        @endif

        .stat-section + .stat-section {
            page-break-before: always;
        }

        table.statistics {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }

        table.statistics tr {
            page-break-inside: avoid;
        }

        table.statistics th,
        table.statistics td {
            border: 1px solid #000;
            padding: 2px 5px;
            font-size: 12px;
        }

        table.statistics th {
            font-weight: bold;
            text-transform: uppercase;
            text-align: center;
            font-size: 11px;
        }

        table.statistics td.num {
            text-align: center;
        }

        table.statistics td.total,
        table.statistics th.total {
            font-weight: bold;
            background: #e5e5e5;
        }

        .empty-message {
            margin-top: 20px;
            font-style: italic;
            text-align: center;
        }
    </style>
</head>
<body>
    @include("pdf.partials.{$letterhead}-header")

    @foreach ($tables as $table)
        @php
            $showGroup = collect($table['rows'])->contains(fn ($row) => $row['team']->group !== null);
        @endphp

        <div class="stat-section">
            <h1>{{ $table['type']->leaderboardTitle() }}</h1>
            <h2>{{ $tournament->name }}</h2>

            <div class="meta"><strong>Categoría:</strong> {{ $category->name }}</div>
            <div class="meta"><strong>Fecha de corte:</strong> {{ now()->locale('es')->translatedFormat('d \d\e F \d\e Y') }}</div>

            @if ($table['rows'] === [])
                <div class="empty-message">Todavía no hay registros de {{ $table['type']->statisticNoun() }} en partidos jugados de esta categoría.</div>
            @else
                <table class="statistics">
                    <thead>
                        <tr>
                            <th style="width: 5%;">#</th>
                            <th>Jugador</th>
                            <th>Equipo</th>
                            @if ($showGroup)
                                <th>Grupo</th>
                            @endif
                            @foreach ($table['phases'] as $phase)
                                <th style="width: 9%;">{{ $phase->name }}</th>
                            @endforeach
                            <th class="total" style="width: 8%;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($table['rows'] as $row)
                            <tr>
                                <td class="num">{{ $row['rank'] }}</td>
                                <td>{{ $row['name'] }}</td>
                                <td>{{ $row['team']->name }}</td>
                                @if ($showGroup)
                                    <td class="num">{{ $row['team']->group?->name ?? 'Único' }}</td>
                                @endif
                                @foreach ($table['phases'] as $phase)
                                    <td class="num">{{ $row['counts'][$phase->id] ?? 0 }}</td>
                                @endforeach
                                <td class="num total">{{ $row['total'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach

    @include("pdf.partials.{$letterhead}-signature")
</body>
</html>
