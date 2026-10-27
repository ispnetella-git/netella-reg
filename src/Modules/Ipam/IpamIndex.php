<?php

/*
 * IpamIndex.php - stan adresacji jednej przestrzeni: adresy, sieci, drzewo.
 *
 * Wejście: wiersze migawki (adres + port z LibreNMS) i aktywne wpisy ręczne.
 * Statusy adresu, jak w module VLAN (dane LibreNMS mają pierwszeństwo):
 *
 *   l  LibreNMS      na aktywnym urządzeniu
 *   s  nieaktualny   tylko na urządzeniach wyłączonych (disabled_devices = stale)
 *   m  ręczny        wpis ręczny "używany"
 *   r  rezerwacja    wpis ręczny "rezerwacja"
 *   g  zniknął       zniknął z LibreNMS, trwa karencja
 *
 * Drzewo jest tylko dla IPv4 (decyzja z 2026-10-27: IPv6 w modelu, UI później).
 * Maski krótsze niż /8 z LibreNMS traktujemy jako podejrzane: adres trafia
 * do drzewa jako pojedynczy host, a sieć nie powstaje.
 *
 * Czyste, bez Laravela.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Ipam;

final class IpamIndex
{
    public const FREE = 'f';
    public const LNMS = 'l';
    public const STALE = 's';
    public const MANUAL = 'm';
    public const RESERVED = 'r';
    public const GONE = 'g';

    public const LABELS = [
        self::LNMS => 'LibreNMS',
        self::STALE => 'nieaktualny (urządzenie wyłączone)',
        self::MANUAL => 'wpis ręczny',
        self::RESERVED => 'rezerwacja',
        self::GONE => 'zniknął z LibreNMS (karencja)',
        self::FREE => 'wolny',
    ];

    private const RANK = [
        self::FREE => 0, self::GONE => 1, self::RESERVED => 2, self::MANUAL => 3, self::STALE => 4, self::LNMS => 5,
    ];

    /** Najkrótsza maska z LibreNMS, z której robimy sieć. */
    public const MIN_LNMS_LEN = 8;

    /** @var array<int,array> ip (v4 int) => ['state', 'rows' => [...], 'manual' => ?array] */
    private array $addr = [];

    /** @var array<string,list<array>> cidr => wiersze LibreNMS z tej sieci */
    private array $networks = [];

    /** @var array<string,array> cidr => prefiks ręczny */
    private array $manualPrefixes = [];

    /** @var list<array> adresy LibreNMS z maską < MIN_LNMS_LEN */
    private array $suspicious = [];

    /** @var list<array> rekordy IPv6 (bez drzewa) */
    private array $v6 = [];

    private ?IpTree $tree = null;

    /**
     * @param  list<array{key:string,data:array,first_seen:string,last_seen:string,gone_at:?string}>  $rows  migawka
     * @param  list<array>  $manual  aktywne wpisy ręczne tej przestrzeni
     * @param  array<int,array>  $spaces  przestrzenie (do przypisania context_name)
     */
    public function __construct(
        array $rows,
        array $manual,
        private readonly int $space,
        array $spaces,
        \DateTimeImmutable $now,
        int $graceDays,
        string $disabledMode,
        private readonly array $levels = [8, 16, 24],
    ) {
        $graceLimit = $now->modify("-{$graceDays} days")->format('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $d = $row['data'];
            if (IpamSpaces::resolve($spaces, $d['ctx'] ?? null) !== $space) {
                continue;
            }
            $state = self::rowState($row, $graceLimit, $disabledMode);
            if ($state === self::FREE) {
                continue;
            }
            $row['state'] = $state;

            if (($d['family'] ?? 4) === 6) {
                $this->v6[] = $row;
                continue;
            }
            $ip = IpCalc::ip2int((string) $d['ip']);
            if ($ip === null) {
                continue;
            }
            $this->addr[$ip]['rows'][] = $row;
            $this->raise($ip, $state);

            $len = (int) $d['len'];
            if ($len < self::MIN_LNMS_LEN) {
                $this->suspicious[] = $row;
            } elseif ($len < 32) {
                $this->networks[IpCalc::cidr(IpCalc::network($ip, $len), $len)][] = $row;
            }
        }

        foreach ($manual as $m) {
            if ((int) $m['family'] !== 4) {
                continue;
            }
            if ($m['kind'] === 'prefix') {
                $this->manualPrefixes[$m['cidr']] = $m;
            } else {
                $ip = (int) $m['start'];
                $this->addr[$ip]['manual'] = $m;
                $this->raise($ip, $m['role'] === 'reserved' ? self::RESERVED : self::MANUAL);
            }
        }
    }

    private static function rowState(array $row, string $graceLimit, string $disabledMode): string
    {
        if ($row['gone_at'] !== null) {
            return $row['gone_at'] >= $graceLimit ? self::GONE : self::FREE;
        }
        if (! empty($row['data']['disabled'])) {
            return $disabledMode === 'ignore' ? self::FREE : self::STALE;
        }

        return self::LNMS;
    }

    private function raise(int $ip, string $state): void
    {
        $cur = $this->addr[$ip]['state'] ?? self::FREE;
        if (self::RANK[$state] > self::RANK[$cur]) {
            $this->addr[$ip]['state'] = $state;
        }
        $this->addr[$ip]['rows'] ??= [];
        $this->addr[$ip]['manual'] ??= null;
    }

    public function space(): int
    {
        return $this->space;
    }

    public function status(int $ip): string
    {
        return $this->addr[$ip]['state'] ?? self::FREE;
    }

    public function address(int $ip): ?array
    {
        return $this->addr[$ip] ?? null;
    }

    /** Czy LibreNMS widzi adres teraz (aktywne albo wyłączone urządzenie). */
    public function knownAddress(int $ip): bool
    {
        return in_array($this->status($ip), [self::LNMS, self::STALE], true);
    }

    /** Czy LibreNMS ma taką sieć na interfejsie (aktywnym albo wyłączonym). */
    public function knownNetwork(string $cidr): bool
    {
        foreach ($this->networks[$cidr] ?? [] as $r) {
            if (in_array($r['state'], [self::LNMS, self::STALE], true)) {
                return true;
            }
        }

        return false;
    }

    /** Czy sieć jest na aktywnym urządzeniu (do przejmowania wpisów). */
    public function activeNetwork(string $cidr): bool
    {
        foreach ($this->networks[$cidr] ?? [] as $r) {
            if ($r['state'] === self::LNMS) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adresy LibreNMS (l, s) w zakresie.
     *
     * @return list<int>
     */
    public function knownInside(int $start, int $end): array
    {
        $out = [];
        foreach ($this->addr as $ip => $a) {
            if ($ip >= $start && $ip <= $end && $this->knownAddress($ip)) {
                $out[] = $ip;
            }
        }
        sort($out);

        return $out;
    }

    /** @return array<string,list<array>> */
    public function networks(): array
    {
        return $this->networks;
    }

    /** @return array<string,array> */
    public function manualPrefixes(): array
    {
        return $this->manualPrefixes;
    }

    /** @return array<int,array> */
    public function addresses(): array
    {
        return $this->addr;
    }

    public function suspicious(): array
    {
        return $this->suspicious;
    }

    public function v6(): array
    {
        return $this->v6;
    }

    /**
     * Ten sam adres na kilku różnych aktywnych urządzeniach.
     *
     * @return array<int,list<string>> ip => urządzenia
     */
    public function duplicates(): array
    {
        $out = [];
        foreach ($this->addr as $ip => $a) {
            $devices = [];
            foreach ($a['rows'] as $r) {
                if ($r['state'] === self::LNMS) {
                    $devices[$r['data']['device_id']] = $r['data']['device'];
                }
            }
            if (count($devices) > 1) {
                $out[$ip] = array_values($devices);
            }
        }
        ksort($out);

        return $out;
    }

    public function tree(): IpTree
    {
        if ($this->tree !== null) {
            return $this->tree;
        }

        $prefixes = [];
        foreach ($this->networks as $cidr => $rows) {
            $c = IpCalc::parseV4($cidr);
            $prefixes[] = ['start' => $c['start'], 'len' => $c['len'], 'src' => 'lnms', 'subnet' => true];
        }
        foreach ($this->manualPrefixes as $cidr => $m) {
            $c = IpCalc::parseV4($cidr);
            $prefixes[] = ['start' => $c['start'], 'len' => $c['len'], 'src' => 'manual',
                'subnet' => in_array($m['role'], ['used', 'pool'], true)];
        }
        $addresses = [];
        foreach (array_keys($this->addr) as $ip) {
            $addresses[] = ['ip' => $ip];
        }

        return $this->tree = new IpTree($prefixes, $addresses, $this->levels);
    }
}
