<?php

/*
 * RipeLookup.php - prefiksy numeru AS z RIPE, do dodania jako agregaty.
 *
 * Źródła (każde opcjonalne, brak odpowiedzi = ostrzeżenie):
 *   bgp    RIPEstat announced-prefixes - co AS ogłasza w BGP (ostatnie 2 tygodnie)
 *   route  baza RIPE, obiekty route/route6 z origin: AS
 *   org    baza RIPE, inetnum/inet6num organizacji z obiektu aut-num
 * Dla prefiksów IPv4 dociąga przydział z bazy RIPE (inetnum: dokładny albo
 * najbliższy szerszy), żeby było widać, czyja to adresacja.
 *
 * Czyste: pobieranie przez wstrzyknięty $fetch (URL -> JSON albo null przy
 * błędzie, [] gdy brak wyników), więc da się testować bez sieci.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Ipam;

final class RipeLookup
{
    public const STAT = 'https://stat.ripe.net/data/';
    public const DB = 'https://rest.db.ripe.net/';
    public const SOURCES = ['bgp' => 'ogłaszany w BGP', 'route' => 'obiekt route w RIPE', 'org' => 'przydział organizacji AS'];

    /** Limit czasu na dociąganie przydziałów (s); reszta prefiksów zostaje bez nich. */
    private const BUDGET = 25.0;

    /** @var callable(string):?array */
    private $fetch;

    /** @param callable(string):?array $fetch */
    public function __construct(callable $fetch, private readonly float $budget = self::BUDGET)
    {
        $this->fetch = $fetch;
    }

    /**
     * "AS61154", "as 61154", "61154" -> 61154. Odrzuca numery prywatne
     * i zarezerwowane - nie ma ich w RIPE.
     *
     * @throws \InvalidArgumentException
     */
    public static function parseAsn(string $text): int
    {
        if (! preg_match('/^\s*(?:AS\s*)?(\d{1,10})\s*$/i', $text, $m)) {
            throw new \InvalidArgumentException('Podaj numer AS, np. AS61154 albo 61154.');
        }
        $n = (int) $m[1];
        if ($n < 1 || $n > 4294967294 || $n === 23456) {
            throw new \InvalidArgumentException("AS{$n} to nie jest poprawny publiczny numer AS.");
        }
        if (($n >= 64496 && $n <= 131071) || $n >= 4200000000) {
            throw new \InvalidArgumentException("AS{$n} to numer prywatny albo zarezerwowany - RIPE nie ma dla niego prefiksów.");
        }

        return $n;
    }

    /**
     * @return array{asn:int,holder:?string,org:?string,org_name:?string,warnings:list<string>,
     *               prefixes:list<array{cidr:string,family:int,sources:list<string>,route_descr:?string,inetnum:?array}>}
     */
    public function lookup(int $asn): array
    {
        $t0 = microtime(true);
        $warn = [];
        $found = []; // cidr => wpis
        $add = function (string $cidr, string $source, ?string $descr = null) use (&$found): void {
            $cidr = self::normCidr($cidr);
            if ($cidr === null) {
                return;
            }
            $found[$cidr] ??= ['cidr' => $cidr, 'family' => str_contains($cidr, ':') ? 6 : 4, 'sources' => [], 'route_descr' => null, 'inetnum' => null];
            if (! in_array($source, $found[$cidr]['sources'], true)) {
                $found[$cidr]['sources'][] = $source;
            }
            if ($descr !== null && $found[$cidr]['route_descr'] === null) {
                $found[$cidr]['route_descr'] = $descr;
            }
        };

        $ov = $this->get(self::STAT . "as-overview/data.json?resource=AS{$asn}&sourceapp=netella-reg");
        $holder = is_array($ov) ? ($ov['data']['holder'] ?? null) : null;
        if ($ov === null) {
            $warn[] = 'RIPEstat (opis AS) nie odpowiedział.';
        }

        $ann = $this->get(self::STAT . "announced-prefixes/data.json?resource=AS{$asn}&sourceapp=netella-reg");
        if ($ann === null) {
            $warn[] = 'RIPEstat (prefiksy ogłaszane w BGP) nie odpowiedział.';
        }
        foreach (is_array($ann) ? ($ann['data']['prefixes'] ?? []) : [] as $p) {
            $add((string) ($p['prefix'] ?? ''), 'bgp');
        }

        $routes = $this->get(self::DB . "search.json?query-string=AS{$asn}&inverse-attribute=origin&type-filter=route&type-filter=route6"
            . '&flags=no-referenced&flags=no-irt');
        if ($routes === null) {
            $warn[] = 'Baza RIPE (obiekty route) nie odpowiedziała.';
        }
        foreach (self::objects($routes) as $o) {
            $a = self::attrs($o);
            $add((string) ($a['route'] ?? $a['route6'] ?? ''), 'route', self::descr($a['descr'] ?? null));
        }

        $org = $orgName = null;
        $aut = $this->get(self::DB . "ripe/aut-num/AS{$asn}.json");
        foreach (self::objects($aut) as $o) {
            $org = self::attrs($o)['org'] ?? null;
        }
        if ($org !== null) {
            foreach (self::objects($this->get(self::DB . 'ripe/organisation/' . rawurlencode($org) . '.json')) as $o) {
                $orgName = self::attrs($o)['org-name'] ?? null;
            }
            $own = $this->get(self::DB . 'search.json?query-string=' . rawurlencode($org)
                . '&inverse-attribute=org&type-filter=inetnum&type-filter=inet6num&flags=no-referenced&flags=no-irt&flags=no-personal');
            foreach (self::objects($own) as $o) {
                $a = self::attrs($o);
                if (isset($a['inet6num'])) {
                    $add($a['inet6num'], 'org');
                    continue;
                }
                $inet = self::inetnum($a, $org);
                if ($inet === null) {
                    continue;
                }
                foreach ($inet['cidrs'] as $c) {
                    $add($c, 'org');
                    $found[$c]['inetnum'] = $inet['info'];
                }
            }
        }

        if ($ann === null && $routes === null) {
            throw new \RuntimeException('Nie udało się pobrać danych z RIPE (RIPEstat i baza RIPE nie odpowiadają). Spróbuj później.');
        }

        // Przydział z bazy RIPE dla prefiksów IPv4 bez niego.
        $skipped = 0;
        foreach ($found as $cidr => $f) {
            if ($f['family'] !== 4 || $f['inetnum'] !== null) {
                continue;
            }
            if (microtime(true) - $t0 > $this->budget) {
                $skipped++;
                continue;
            }
            $res = $this->get(self::DB . 'search.json?query-string=' . rawurlencode($cidr)
                . '&type-filter=inetnum&flags=no-referenced&flags=no-irt&flags=no-personal');
            foreach (self::objects($res) as $o) {
                $inet = self::inetnum(self::attrs($o));
                if ($inet !== null) {
                    $found[$cidr]['inetnum'] = $inet['info'];
                }
            }
        }
        if ($skipped) {
            $warn[] = "Baza RIPE odpowiada wolno: bez danych o przydziale zostało {$skipped} prefiksów.";
        }

        $list = array_values($found);
        usort($list, fn ($a, $b) => self::sortKey($a['cidr']) <=> self::sortKey($b['cidr']));

        return ['asn' => $asn, 'holder' => $holder, 'org' => $org, 'org_name' => $orgName, 'warnings' => $warn, 'prefixes' => $list];
    }

    private function get(string $url): ?array
    {
        return ($this->fetch)($url);
    }

    /** @return list<array> */
    private static function objects(?array $json): array
    {
        $o = $json['objects']['object'] ?? [];

        return is_array($o) ? array_values($o) : [];
    }

    /** Pierwsza wartość każdego atrybutu obiektu bazy RIPE. */
    private static function attrs(array $o): array
    {
        $out = [];
        foreach ($o['attributes']['attribute'] ?? [] as $x) {
            if (isset($x['name'], $x['value'])) {
                $out[$x['name']] ??= trim((string) $x['value']);
            }
        }

        return $out;
    }

    /** Opis z route bez śmieci typu "abuse-mail: ...". */
    private static function descr(?string $d): ?string
    {
        return $d === null || $d === '' || preg_match('/^abuse|@/i', $d) ? null : mb_substr($d, 0, 64);
    }

    /**
     * Obiekt inetnum -> lista CIDR-ów i opis. Pomija IANA-BLK i podobne
     * (cała przestrzeń, status UNSPECIFIED).
     *
     * @return ?array{cidrs:list<string>,info:array}
     */
    private static function inetnum(array $a, ?string $org = null): ?array
    {
        if (! preg_match('/^(\S+)\s*-\s*(\S+)$/', $a['inetnum'] ?? '', $m)) {
            return null;
        }
        $s = IpCalc::ip2int($m[1]);
        $e = IpCalc::ip2int($m[2]);
        if ($s === null || $e === null || $s > $e || str_contains($a['status'] ?? '', 'UNSPECIFIED') || $e - $s >= 0x00FFFFFF) {
            return null;
        }
        $cidrs = array_map(fn ($b) => IpCalc::cidr($b['start'], $b['len']), IpCalc::rangeToCidrs($s, $e));

        return ['cidrs' => $cidrs, 'info' => [
            'range' => count($cidrs) === 1 ? $cidrs[0] : $m[1] . ' - ' . $m[2],
            'netname' => $a['netname'] ?? null,
            'status' => $a['status'] ?? null,
            'org' => $a['org'] ?? $org,
        ]];
    }

    /** Postać kanoniczna prefiksu IPv4/IPv6 albo null. */
    public static function normCidr(string $cidr): ?string
    {
        if (! preg_match('#^([0-9a-fA-F:.]+)/(\d{1,3})$#', trim($cidr), $m)) {
            return null;
        }
        if (str_contains($m[1], ':')) {
            $ip = IpCalc::normalizeV6($m[1]);

            return $ip === null || (int) $m[2] > 128 ? null : strtolower($ip) . '/' . (int) $m[2];
        }
        try {
            return IpCalc::parseV4($m[1] . '/' . $m[2])['cidr'];
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** IPv4 według adresu, potem IPv6. */
    private static function sortKey(string $cidr): array
    {
        if (str_contains($cidr, ':')) {
            [$ip, $len] = explode('/', $cidr);

            return [6, bin2hex((string) inet_pton($ip)), (int) $len];
        }
        $p = IpCalc::parseV4($cidr);

        return [4, sprintf('%010d', $p['start']), $p['len']];
    }
}
