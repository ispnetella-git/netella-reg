<?php

/*
 * VlanCollector.php - odczyt VLAN-ów z bazy LibreNMS.
 *
 * Źródła (wszystkie tylko do odczytu):
 *   vlans        definicje VLAN-ów na switchach (numer, nazwa)
 *   ports_vlans  przypisanie portów, tagowane lub nie
 *   ports        nazwy interfejsów: Vlanif1400, Po2.100 itd. (VlanName)
 *
 * Wynik: jeden rekord na parę urządzenie + VLAN, klucz "v<vlan>:d<device_id>".
 * Numer na początku klucza pozwala wyszukać historię VLAN-u po prefiksie.
 * Listy są sortowane, żeby hash migawki nie zmieniał się bez powodu.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Vlan;

use Illuminate\Support\Facades\DB;

final class VlanCollector
{
    public static function key(int $vlan, int $deviceId): string
    {
        return "v{$vlan}:d{$deviceId}";
    }

    /** @return array<string,array> */
    public function collect(): array
    {
        $devices = [];
        foreach (DB::table('devices')->get(['device_id', 'hostname', 'sysName', 'disabled']) as $d) {
            $devices[(int) $d->device_id] = [
                'device' => (string) ($d->sysName ?: $d->hostname),
                'hostname' => (string) $d->hostname,
                'disabled' => (bool) $d->disabled,
            ];
        }

        $out = [];
        $touch = function (int $deviceId, int $vlan) use (&$out, $devices): ?string {
            if (! isset($devices[$deviceId]) || $vlan < VlanName::MIN || $vlan > VlanName::MAX) {
                return null;
            }
            $key = self::key($vlan, $deviceId);
            $out[$key] ??= [
                'vlan' => $vlan,
                'device_id' => $deviceId,
                'device' => $devices[$deviceId]['device'],
                'hostname' => $devices[$deviceId]['hostname'],
                'disabled' => $devices[$deviceId]['disabled'],
                'name' => null,
                'defined' => false,
                'tagged' => [],
                'untagged' => [],
                'l3' => [],
                'subif' => [],
            ];

            return $key;
        };

        foreach (DB::table('vlans')->get(['device_id', 'vlan_vlan', 'vlan_name']) as $v) {
            $key = $touch((int) $v->device_id, (int) $v->vlan_vlan);
            if ($key !== null) {
                $out[$key]['defined'] = true;
                $name = trim((string) $v->vlan_name);
                if ($name !== '') {
                    $out[$key]['name'] = $name;
                }
            }
        }

        $pv = DB::table('ports_vlans as pv')
            ->join('ports as p', 'p.port_id', '=', 'pv.port_id')
            ->where('p.deleted', 0)
            ->get(['pv.device_id', 'pv.vlan', 'pv.untagged', 'p.ifName', 'p.ifDescr']);
        foreach ($pv as $r) {
            $key = $touch((int) $r->device_id, (int) $r->vlan);
            if ($key !== null) {
                $if = (string) ($r->ifName ?: $r->ifDescr);
                $out[$key][$r->untagged ? 'untagged' : 'tagged'][] = $if;
            }
        }

        $ports = DB::table('ports')->where('deleted', 0)
            ->get(['device_id', 'ifName', 'ifDescr', 'ifAlias']);
        foreach ($ports as $p) {
            $if = (string) ($p->ifName ?: $p->ifDescr);
            $hit = VlanName::fromIfName($if);
            if ($hit === null) {
                continue;
            }
            $key = $touch((int) $p->device_id, $hit['vlan']);
            if ($key !== null) {
                $alias = trim((string) $p->ifAlias);
                $out[$key][$hit['kind']][] = $alias !== '' && $alias !== $if ? "$if ($alias)" : $if;
            }
        }

        foreach ($out as &$rec) {
            foreach (['tagged', 'untagged', 'l3', 'subif'] as $f) {
                $rec[$f] = array_values(array_unique($rec[$f]));
                natcasesort($rec[$f]);
                $rec[$f] = array_values($rec[$f]);
            }
        }
        unset($rec);

        ksort($out, SORT_NATURAL);

        return $out;
    }
}
