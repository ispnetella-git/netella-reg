{{-- Sekcja modułu VLAN na stronie urządzenia: VLAN-y widziane na tym urządzeniu. --}}
<div style="margin-bottom: 6px;">
    <strong>{{ $moduleName }}</strong> ({{ count($vlanRows) }}):
    <span style="font-family: monospace; word-break: break-all;">{{ $vlanList }}</span>
</div>
<details>
    <summary style="cursor: pointer;">Szczegóły</summary>
    <table class="table table-condensed table-hover" style="margin: 6px 0 0;">
        <thead>
        <tr><th>VLAN</th><th>Nazwa</th><th>Tagowane</th><th>Nietagowane</th><th>L3 / subinterfejsy</th></tr>
        </thead>
        <tbody>
        @foreach($vlanRows as $r)
            @php($d = $r['data'])
            <tr>
                <td><a href="{{ url('plugin/Netella-Reg') }}?vlan={{ $d['vlan'] }}#vlan">{{ $d['vlan'] }}</a></td>
                <td>{{ $d['name'] ?? '' }}</td>
                <td>{{ count($d['tagged'] ?? []) }}</td>
                <td>{{ implode(', ', array_slice($d['untagged'] ?? [], 0, 8)) }}@if(count($d['untagged'] ?? []) > 8) … @endif</td>
                <td>{{ implode(', ', array_merge($d['l3'] ?? [], $d['subif'] ?? [])) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</details>
