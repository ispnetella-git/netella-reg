<?php

/*
 * IpamCollector.php - odczyt adresacji z bazy LibreNMS.
 *
 * Źródła (tylko odczyt): ipv4_addresses, ipv6_addresses, ports, devices.
 * Jeden rekord na parę adres/maska + port, klucz "a:<ip>/<maska>:p<port_id>".
 * Adres na początku klucza pozwala wyszukać jego historię po prefiksie.
 *
 * Pomijamy zakresy z ustawienia ipam_ignore (domyślnie loopback, link-local)
 * oraz interfejsy pasujące do ipam_ignore_if (domyślnie wewnętrzne interfejsy
 * Junipera emN i jsrv z adresami 128.0.0.x/2).
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Ipam;

use Illuminate\Support\Facades\DB;
use Ispnetella\NetellaReg\Modules\Vlan\VlanName;

final class IpamCollector
{
    /**
     * @param  list<array{start:int,end:int}>  $ignoreV4  zakresy IPv4 do pominięcia
     * @param  string  $ignoreIf  wyrażenie regularne nazw interfejsów do pominięcia ('' = brak)
     */
    public function __construct(private readonly array $ignoreV4, private readonly string $ignoreIf)
    {
    }

    public static function key(string $ip, int $len, int $portId): string
    {
        return "a:{$ip}/{$len}:p{$portId}";
    }

    /** @return array<string,array> */
    public function collect(): array
    {
        $out = [];

        $v4 = DB::table('ipv4_addresses as a')
            ->join('ports as p', 'p.port_id', '=', 'a.port_id')
            ->join('devices as d', 'd.device_id', '=', 'p.device_id')
            ->where('p.deleted', 0)
            ->get(['a.ipv4_address as ip', 'a.ipv4_prefixlen as len', 'a.context_name as ctx', 'p.port_id', 'p.ifName',
                'p.ifDescr', 'p.ifAlias', 'd.device_id', 'd.hostname', 'd.sysName', 'd.disabled']);

        foreach ($v4 as $r) {
            $int = IpCalc::ip2int((string) $r->ip);
            if ($int === null || $this->ignoredV4($int) || $this->ignoredIf($r)) {
                continue;
            }
            $len = max(0, min(32, (int) $r->len));
            $out[self::key((string) $r->ip, $len, (int) $r->port_id)] = $this->record(4, (string) $r->ip, $len,
                IpCalc::cidr(IpCalc::network($int, $len), $len), $r);
        }

        $v6 = DB::table('ipv6_addresses as a')
            ->join('ports as p', 'p.port_id', '=', 'a.port_id')
            ->join('devices as d', 'd.device_id', '=', 'p.device_id')
            ->where('p.deleted', 0)
            ->get(['a.ipv6_compressed as ip', 'a.ipv6_prefixlen as len', 'a.context_name as ctx', 'p.port_id', 'p.ifName',
                'p.ifDescr', 'p.ifAlias', 'd.device_id', 'd.hostname', 'd.sysName', 'd.disabled']);

        foreach ($v6 as $r) {
            $ip = IpCalc::normalizeV6((string) $r->ip);
            // IPv6: na razie tylko zapis. Pomijamy link-local i loopback.
            if ($ip === null || str_starts_with(strtolower($ip), 'fe80:') || $ip === '::1' || $this->ignoredIf($r)) {
                continue;
            }
            $len = max(0, min(128, (int) $r->len));
            $out[self::key($ip, $len, (int) $r->port_id)] = $this->record(6, $ip, $len, null, $r);
        }

        ksort($out, SORT_NATURAL);

        return $out;
    }

    private function record(int $family, string $ip, int $len, ?string $network, object $r): array
    {
        $if = (string) ($r->ifName ?: $r->ifDescr);
        $vlan = VlanName::fromIfName($if);
        $alias = trim((string) $r->ifAlias);

        return [
            'family' => $family,
            'ip' => $ip,
            'len' => $len,
            'network' => $network,
            'ctx' => trim((string) $r->ctx) ?: null,
            'device_id' => (int) $r->device_id,
            'device' => (string) ($r->sysName ?: $r->hostname),
            'hostname' => (string) $r->hostname,
            'disabled' => (bool) $r->disabled,
            'port_id' => (int) $r->port_id,
            'if' => $if,
            'alias' => $alias !== '' && $alias !== $if ? $alias : null,
            'vlan' => $vlan['vlan'] ?? null,
        ];
    }

    private function ignoredV4(int $ip): bool
    {
        foreach ($this->ignoreV4 as $r) {
            if ($ip >= $r['start'] && $ip <= $r['end']) {
                return true;
            }
        }

        return false;
    }

    private function ignoredIf(object $r): bool
    {
        if ($this->ignoreIf === '') {
            return false;
        }
        $if = (string) ($r->ifName ?: $r->ifDescr);

        return @preg_match('/' . str_replace('/', '\/', $this->ignoreIf) . '/i', $if) === 1;
    }

    /**
     * Lista zakresów do pominięcia z tekstu ustawień ("127.0.0.0/8, 169.254.0.0/16").
     * Błędne pozycje są pomijane, żeby literówka nie zatrzymała synchronizacji.
     *
     * @return list<array{start:int,end:int}>
     */
    public static function parseIgnore(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY) as $p) {
            try {
                $c = IpCalc::parseV4($p);
                $out[] = ['start' => $c['start'], 'end' => $c['end']];
            } catch (\InvalidArgumentException) {
                // pomijamy
            }
        }

        return $out;
    }
}
