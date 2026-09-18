{{-- Needs: $appLogo (data URI). Generic MiTorneo letterhead, for organizers without their own. --}}
<header class="default">
    <table>
        <tr>
            <td class="crest" style="width: 14%;"><img src="{{ $appLogo }}" alt="{{ config('app.name') }}"></td>
            <td class="brand">
                <div class="brand-name">{{ config('app.name') }}</div>
                <div class="brand-tagline">Gestión de torneos de fútbol</div>
            </td>
        </tr>
    </table>
    <div class="brand-rule"></div>
</header>

<footer class="default">Documento generado con {{ config('app.name') }} · {{ now()->locale('es')->translatedFormat('d \d\e F \d\e Y') }}</footer>
