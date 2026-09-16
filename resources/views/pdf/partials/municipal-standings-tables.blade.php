{{-- Needs: $tables (StandingsService::tablesForPhase() shape) --}}
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
