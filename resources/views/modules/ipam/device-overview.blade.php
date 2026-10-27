{{-- Sekcja modułu adresacji na stronie urządzenia: adresy widziane na tym urządzeniu. --}}
<div style="margin: 8px 0 6px;">
    <strong>{{ $moduleName }}</strong> ({{ count($ipRows) }})
</div>
<details>
    <summary style="cursor: pointer;">Adresy</summary>
    <table class="table table-condensed table-hover" style="margin: 6px 0 0;">
        <thead>
        <tr><th>Adres</th><th>Sieć</th><th>Interfejs</th><th>Opis portu</th><th>VLAN</th></tr>
        </thead>
        <tbody>
        @foreach($ipRows as $r)
            @php($d = $r['data'])
            <tr>
                <td><a href="{{ url('plugin/Netella-Reg') }}?ip={{ urlencode($d['ip']) }}#ipam" style="font-family: monospace;">{{ $d['ip'] }}/{{ $d['len'] }}</a></td>
                <td style="font-family: monospace;">{{ $d['network'] ?? '' }}</td>
                <td>{{ $d['if'] }}</td>
                <td>{{ $d['alias'] ?? '' }}</td>
                <td>@if($d['vlan'])<a href="{{ url('plugin/Netella-Reg') }}?vlan={{ $d['vlan'] }}#vlan">{{ $d['vlan'] }}</a>@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</details>
