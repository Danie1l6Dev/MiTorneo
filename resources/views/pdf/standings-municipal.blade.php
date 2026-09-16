<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 190px 40px 60px 40px; }

        body {
            font-family: 'Times New Roman', Times, serif;
            color: #000;
            font-size: 15px;
        }

        header {
            position: fixed;
            top: -170px;
            left: 0;
            right: 0;
            height: 170px;
            text-align: center;
        }

        header table {
            width: 100%;
        }

        header .crest img {
            height: 110px;
        }

        header .wordmark {
            vertical-align: middle;
            padding: 0 10px;
        }

        header .wordmark img {
            width: 100%;
        }

        header .letterhead-line {
            font-size: 12px;
            line-height: 1.5;
        }

        header .letterhead-line.strong {
            font-weight: bold;
            font-size: 13px;
        }

        footer {
            position: fixed;
            bottom: -40px;
            left: 0;
            right: 0;
            height: 40px;
            text-align: center;
        }

        h1 {
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            margin: 4px 0 2px 0;
            text-transform: uppercase;
        }

        h2 {
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            margin: 0 0 18px 0;
            text-transform: uppercase;
        }

        .meta {
            font-size: 15px;
            margin-bottom: 5px;
        }

        .meta strong {
            text-transform: uppercase;
        }

        table.standings {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 22px;
        }

        table.standings caption {
            text-align: left;
            font-weight: bold;
            font-size: 15px;
            text-transform: uppercase;
            padding: 8px 0;
        }

        table.standings th,
        table.standings td {
            border: 1px solid #000;
            padding: 2px 6px;
            font-size: 15px;
        }

        table.standings th {
            font-weight: bold;
            text-transform: uppercase;
            text-align: center;
        }

        table.standings td.team {
            text-align: left;
        }

        table.standings td.num {
            text-align: center;
        }

        .signature {
            margin-top: 40px;
            text-align: center;
        }

        .signature img {
            height: 56px;
        }

        .signature .line {
            display: block;
            width: 240px;
            margin: -10px auto 4px auto;
            border-top: 1px solid #000;
        }

        .signature .role {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <header>
        <table>
            <tr>
                <td class="crest" style="width: 22%;"><img src="{{ $lifutguaLogo }}" alt="LIFUTGUA"></td>
                <td class="wordmark"><img src="{{ $wordmark }}" alt="LIGA DE FUTBOL DE LA GUAJIRA - LIFUTGUA"></td>
                <td class="crest" style="width: 22%;"><img src="{{ $difutbolLogo }}" alt="DIFUTBOL"></td>
            </tr>
        </table>
        <div class="letterhead-line strong">NIT.800.237.808-4</div>
        <div class="letterhead-line strong">Personería Jurídica No.761 GUAJIRA</div>
        <div class="letterhead-line">Reconocimiento de Coldeportes Resolución No 000810</div>
    </header>

    <footer></footer>

    <h1>Tabla de posiciones oficial</h1>
    <h2>{{ $tournament->name }}</h2>

    <div class="meta"><strong>Categoría:</strong> {{ $category->name }}</div>
    <div class="meta"><strong>Fase:</strong> {{ $phase->name }}</div>
    <div class="meta"><strong>Fecha de corte:</strong> {{ now()->locale('es')->translatedFormat('d \d\e F \d\e Y') }}</div>

    @foreach ($tables as $table)
        <table class="standings">
            <caption>{{ $table['label'] }}</caption>
            <thead>
                <tr>
                    <th style="width: 6%;">Pos</th>
                    <th>Club</th>
                    <th style="width: 6%;">PJ</th>
                    <th style="width: 6%;">PG</th>
                    <th style="width: 6%;">PE</th>
                    <th style="width: 6%;">PP</th>
                    <th style="width: 6%;">GF</th>
                    <th style="width: 6%;">GC</th>
                    <th style="width: 6%;">DG</th>
                    <th style="width: 8%;">PTS</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($table['rows'] as $index => $row)
                    <tr>
                        <td class="num">{{ $index + 1 }}</td>
                        <td class="team">
                            {{ $row['team']->name }}
                            @if ($row['expelled'])
                                (Expulsado)
                            @endif
                        </td>
                        <td class="num">{{ $row['played'] }}</td>
                        <td class="num">{{ $row['won'] }}</td>
                        <td class="num">{{ $row['drawn'] }}</td>
                        <td class="num">{{ $row['lost'] }}</td>
                        <td class="num">{{ $row['goals_for'] }}</td>
                        <td class="num">{{ $row['goals_against'] }}</td>
                        <td class="num">{{ $row['goal_difference'] }}</td>
                        <td class="num">{{ $row['points'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    <div class="signature">
        <img src="{{ $signature }}" alt="Firma del coordinador">
        <div class="line"></div>
        <div class="role">Coordinador</div>
    </div>
</body>
</html>
