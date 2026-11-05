{{-- Pomoc: zwięzły opis ról wpisów ręcznych. Zwinięta domyślnie, przełączana
     linkiem „pomoc/instrukcja” w nagłówku (jak we wtyczce ZTE ONU).
     Listy ról idą z kodu (IpamManual, VlanManual), więc nowa rola pojawi się
     sama; brakujący opis zostaje pusty. --}}
@php
    $nregIp = \Ispnetella\NetellaReg\Modules\Ipam\IpamManual::class;
    $nregDesc = [
        'prefix' => [
            'aggregate' => nreg_t('grupuje sieci w drzewie, np. przydział z RIPE; sam nie zajmuje adresów'),
            'pool' => nreg_t('blok dla klientów (PPPoE, CGNAT); liczony jak sieć, LibreNMS go nie przejmuje'),
            'reserved' => nreg_t('blok trzymany wolny; nie da się go dodać, gdy LibreNMS ma w nim adresy'),
            'used' => nreg_t('sieć w użyciu, której LibreNMS nie widzi; gdy się pojawi, wpis zostaje jako opis'),
            'release' => nreg_t('sieć do opróżnienia; można ją oznaczyć także wtedy, gdy jest w LibreNMS'),
        ],
        'range' => [
            'dhcp' => nreg_t('przeznaczenie zakresu; adres z LibreNMS w środku jest zgłaszany jako możliwy błąd'),
            'pppoe' => nreg_t('jak wyżej'),
            'nat' => nreg_t('jak wyżej'),
            'static' => nreg_t('jak wyżej'),
            'reserved' => nreg_t('zakres trzymany wolny; nie może zawierać adresów z LibreNMS'),
            'release' => nreg_t('zakres do opróżnienia; adresy z LibreNMS w środku nie są błędem'),
        ],
        'address' => [
            'used' => nreg_t('adres w użyciu, którego LibreNMS nie widzi; gdy się pojawi, wpis zostaje jako opis'),
            'reserved' => nreg_t('adres trzymany wolny'),
            'release' => nreg_t('adres do zwolnienia; można go oznaczyć także wtedy, gdy jest w LibreNMS'),
        ],
    ];
    $nregKinds = [
        'prefix' => nreg_t('Prefiks'),
        'range' => nreg_t('Zakres a-b (leży w jednej sieci albo prefiksie, zajęty w całości)'),
        'address' => nreg_t('Adres (pojedynczy albo kilka naraz)'),
    ];
@endphp
<div class="panel panel-default" id="nreg-help" style="display:none">
    <div class="panel-heading"><strong>{{ nreg_t('Role wpisów ręcznych') }}</strong></div>
    <div class="panel-body nreg-help">
        <p>{{ nreg_t('Dane z LibreNMS mają pierwszeństwo. Wpisy ręczne dopisują to, czego LibreNMS nie widzi, albo opisują to, co widzi. Adresu, VLAN-u ani sieci „używanej” widocznych w LibreNMS nie da się dodać ręcznie (wyjątek: „do zwolnienia”).') }}</p>
        <div class="row">
            @foreach($nregKinds as $nregKind => $nregKindLabel)
                <div class="col-md-4">
                    <h5>{{ $nregKindLabel }}</h5>
                    <ul>
                        @foreach($nregIp::roleLabels($nregIp::rolesFor($nregKind)) as $nregRole => $nregRoleLabel)
                            <li><strong>{{ $nregRoleLabel }}</strong>@if(!empty($nregDesc[$nregKind][$nregRole])) &mdash; {{ $nregDesc[$nregKind][$nregRole] }}@endif</li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
        <p class="nreg-sub">{{ nreg_t('IPv6: te same role; w zakresach nie ma puli PPPoE ani NAT, pula DHCP to DHCPv6.') }}</p>
        <h5>{{ nreg_t('VLAN-y') }}</h5>
        <ul>
            <li><strong>{{ nreg_t('używany') }}</strong> &mdash; {{ nreg_t('numer w użyciu, którego LibreNMS nie widzi; gdy się pojawi, wpis zostaje jako opis') }}</li>
            <li><strong>{{ nreg_t('rezerwacja') }}</strong> &mdash; {{ nreg_t('numer trzymany wolny') }}</li>
            <li><strong>QinQ</strong> &mdash; {{ nreg_t('zewnętrzny tag (S-VLAN); pole we wpisie albo przycisk „Oznacz jako QinQ” przy numerze z LibreNMS; nie zmienia zajętości') }}</li>
        </ul>
        <h5>{{ nreg_t('Wspólne') }}</h5>
        <ul>
            <li><strong>{{ nreg_t('Ważne do') }}</strong> &mdash; {{ nreg_t('po terminie wpis nadal zajmuje zasób, ale jest oznaczony „termin minął”') }}</li>
            <li><strong>{{ nreg_t('zniknął z LibreNMS (karencja)') }}</strong> &mdash; {{ nreg_t('przez {n} dni po zniknięciu numer albo adres liczy się jako zajęty, potem jest wolny', ['n' => \Ispnetella\NetellaReg\Core\Settings::graceDays()]) }}</li>
        </ul>
    </div>
</div>
