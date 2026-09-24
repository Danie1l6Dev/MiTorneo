{{--
    Needs: $tournament, $category (null = every category), $sections
    (MatchProgrammingReportService::sections()), plus the letterhead vars
    (PdfLetterheadService).

    Modeled on the league's own programming sheets: each fecha on its own
    page, split by category, then one "LUGAR / DÍA" table per venue + day:
    HORA | CLUB | VS | CLUB | GRUPO. A block with no venue has no LUGAR
    line; one with no date has no DÍA line and no HORA column.

    Tables are allowed to split across pages (dompdf repeats their header
    row on the next page) -- forcing a whole table onto one page left the
    first page blank whenever a fecha had more matches than fit on it.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        {{-- A little more top room than the other exports: here a table can
             continue at the very top of a page, right under the letterhead. --}}
        @page { margin: {{ $letterhead === 'municipal' ? '205px' : '135px' }} 40px 60px 40px; }

        @include('pdf.partials.municipal-style')

        {{-- The letterhead is positioned relative to the content box, so the
             bigger top margin above would drag it down with it -- pull it
             back up to open the gap for real. --}}
        @if ($letterhead === 'municipal')
            header { top: -200px; }
        @else
            header.default { top: -110px; }
        @endif

        .fecha-section + .fecha-section {
            page-break-before: always;
        }

        .fecha-title {
            font-size: 17px;
            font-weight: bold;
            text-transform: uppercase;
            text-align: center;
            margin: 10px 0 4px 0;
        }

        .category-title {
            margin-top: 20px;
            padding: 4px 8px;
            border: 1px solid #000;
            background: #e5e5e5;
            font-size: 15px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .block {
            margin-top: 14px;
        }

        .block .line {
            font-size: 14px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 3px;
        }

        table.programming {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }

        table.programming tr {
            page-break-inside: avoid;
        }

        table.programming th,
        table.programming td {
            border: 1px solid #000;
            padding: 2px 6px;
            font-size: 13px;
            text-align: center;
            text-transform: uppercase;
        }

        table.programming th {
            font-weight: bold;
        }
    </style>
</head>
<body>
    @include("pdf.partials.{$letterhead}-header")

    <h1>Programación oficial</h1>
    <h2 style="margin-bottom: 4px;">{{ $tournament->name }}</h2>

    @foreach ($sections as $section)
        <div class="fecha-section">
            <div class="fecha-title">{{ $section['title'] }}</div>

            @foreach ($section['categories'] as $categorySection)
                <div class="category-title">Categoría: {{ $categorySection['category']->name }}</div>

                @foreach ($categorySection['blocks'] as $block)
                    <div class="block">
                        @if ($block['venue'])
                            <div class="line">Lugar: {{ $block['venue'] }}</div>
                        @endif
                        @if ($block['day'])
                            <div class="line">Día: {{ $block['day']->locale('es')->translatedFormat('l j \d\e F \d\e Y') }}</div>
                        @endif

                        <table class="programming">
                            <thead>
                                <tr>
                                    @if ($block['day'])
                                        <th style="width: 13%;">Hora</th>
                                    @endif
                                    <th>Club</th>
                                    <th style="width: 7%;">Vs</th>
                                    <th>Club</th>
                                    <th style="width: 15%;">Grupo</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($block['matches'] as $match)
                                    <tr>
                                        @if ($block['day'])
                                            <td>{{ $match->scheduled_at->format('g:i A') }}</td>
                                        @endif
                                        <td>{{ $match->homeTeam->name }}</td>
                                        <td>Vs</td>
                                        <td>{{ $match->awayTeam->name }}</td>
                                        <td>{{ $match->group?->name ?? 'Único' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            @endforeach
        </div>
    @endforeach

    @include("pdf.partials.{$letterhead}-signature")
</body>
</html>
