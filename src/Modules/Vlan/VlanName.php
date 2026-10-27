<?php

/*
 * VlanName.php - numer VLAN-u z nazwy interfejsu.
 *
 * Tabela vlans LibreNMS jest pusta dla routerów (ASR, C8500, MX204),
 * a VLAN-y siedzą tam w subinterfejsach. Rozpoznajemy dwa rodzaje:
 *
 *   l3     interfejs VLAN-u: Vlanif1400 (Huawei), Vlan1400 (Cisco),
 *          Vlan-interface1400 (H3C), irb.1400 (Juniper), vlan1600 lub
 *          vlan1600-kamery (MikroTik, nazwa nadana przez operatora)
 *   subif  subinterfejs z numerem: Po2.100, Gi0/0/0.1400, ae0.1400
 *
 * Numer subinterfejsu to zwykle tag dot1q, ale nie musi nim być - dlatego
 * rodzaj jest zapisany osobno i widoczny w UI. Interfejsy wewnętrzne
 * Junipera (lo0.16384, lt-, pp0 itd.) pomijamy.
 *
 * Czyste, bez Laravela.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Vlan;

final class VlanName
{
    public const MIN = 1;
    public const MAX = 4094;

    /** Podstawy subinterfejsów, które nie niosą VLAN-ów. */
    private const SKIP_BASE = '/^(lo|loopback|inloopback|null|nu|lsi|pp|pip|jsrv|tap|dsc|esi|fti|gre|ipip|mtun|pimd|pime|rbeb|vtep|em|fxp|me|bme|cbp|demux|irb|vlan|vme|gr-|ip-|lt-|mt-|pd-|pe-|ut-|vt-|sp-|ms-|si-)/i';

    /** @return array{vlan:int,kind:string}|null */
    public static function fromIfName(string $ifName): ?array
    {
        $n = trim($ifName);

        if ($n === '') {
            return null;
        }

        // Interfejsy VLAN-u. Kolejność ma znaczenie: Vlanif przed Vlan.
        if (preg_match('/^(?:vlanif|vlan-interface|irb\.)(\d{1,4})$/i', $n, $m)
            || preg_match('/^vlan[-_. ]?(\d{1,4})(?!\d)/i', $n, $m)) {
            return self::make((int) $m[1], 'l3');
        }

        // Subinterfejs: <podstawa>.<numer>, podstawa zawiera cyfrę (Po2, Gi0/0/0, ae0).
        if (preg_match('/^([A-Za-z][A-Za-z0-9\/:\-]*\d)\.(\d{1,4})$/', $n, $m)
            && ! preg_match(self::SKIP_BASE, $m[1])) {
            return self::make((int) $m[2], 'subif');
        }

        return null;
    }

    private static function make(int $vlan, string $kind): ?array
    {
        return $vlan >= self::MIN && $vlan <= self::MAX ? ['vlan' => $vlan, 'kind' => $kind] : null;
    }
}
