<?php

/*
 * Ip6Tree.php - drzewo prefiksów IPv6 z zajętością liczoną w blokach /64.
 *
 * Te same zasady co w IPv4 (IpTree):
 *   - węzły: sieci z LibreNMS, prefiksy ręczne, adresacja wewnętrzna (src 'implicit',
 *     np. ULA fc00::/7) i węzły wirtualne na poziomach grupowania (domyślnie /32,
 *     /48, /56, /64) - tylko wewnątrz prawdziwego prefiksu, który nie jest siecią;
 *   - w korzeniu nie ma masek dłuższych niż /48 (ROOT_LEN): drobniejsze sieci
 *     i adresy spoza prefiksów trafiają do wirtualnej grupy /48 z meta 'outside'.
 *
 * Inaczej niż w IPv4: w prefiksie szerszym niż /64 zajętość i wolne miejsce liczymy
 * w blokach /64 (adres albo /127 zajmuje całą swoją /64), bo liczba adresów nic
 * nie mówi. W samej /64 i węższych liczymy adresy (np. pula DHCPv6 w sieci /64
 * i wolne podzakresy obok niej). Nie ma adresu
 * rozgłoszeniowego. Liczby są \GMP, do JSON-a idą jako tekst.
 *
 * Zakresy ręczne (np. pula DHCPv6 a-b) nie są węzłami: należą do najgłębszego
 * węzła, który mieści je w całości, i liczą się w nim jako zajęte.
 *
 * Czyste, bez Laravela.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Ipam6;

final class Ip6Tree
{
    /** Najdłuższa maska węzła w korzeniu drzewa. */
    public const ROOT_LEN = 48;

    /** @var array<string,array> cidr => węzeł */
    private array $nodes = [];

    /** @var list<array{ip:\GMP,hex:string,node:?string}> */
    private array $addresses = [];

    /** @var list<string> */
    private array $roots = [];

    /** @var list<string> węzły posortowane (start, len) */
    private array $sorted = [];

    /** @var array<int,array{id:int,start:\GMP,end:\GMP,node:?string}> */
    private array $ranges = [];

    /**
     * @param  list<array{start:\GMP,len:int,src:string,meta?:array,subnet?:bool}>  $prefixes
     * @param  list<\GMP>  $addresses
     * @param  list<int>  $levels
     * @param  list<array{id:int,start:\GMP,end:\GMP}>  $ranges  zakresy ręczne
     */
    public function __construct(array $prefixes, array $addresses, array $levels = [32, 48, 56, 64], array $ranges = [])
    {
        foreach ($prefixes as $p) {
            $this->addNode($p['start'], $p['len'], $p['src'], $p['meta'] ?? [], (bool) ($p['subnet'] ?? false));
        }

        sort($levels);
        $anchors = array_merge(
            array_map(fn ($n) => [$n['start'], $n['len']], array_values($this->nodes)),
            array_map(fn ($ip) => [$ip, 128], $addresses),
            // Zakres jest kotwicą poziomów, w które mieści się w całości.
            array_map(fn ($r) => [$r['start'], Ip6Calc::commonLen($r['start'], $r['end']) + 1], $ranges)
        );
        foreach ($anchors as [$start, $len]) {
            foreach ($levels as $lvl) {
                if ($lvl < $len) {
                    $this->addNode(Ip6Calc::network($start, $lvl), $lvl, 'virtual', [], false);
                }
            }
        }
        $this->relink();

        // Poziomy grupowania tylko w agregatach (jak w IPv4).
        $drop = array_filter(array_keys($this->nodes), fn ($c) => isset($this->nodes[$c]['sources']['virtual'])
            && ($this->inSubnet($c) || ! $this->inAggregate($c)));
        if ($drop !== []) {
            foreach ($drop as $c) {
                unset($this->nodes[$c]);
            }
            $this->relink();
        }

        $this->groupRoot($addresses);

        foreach ($addresses as $ip) {
            $node = $this->deepest($ip);
            $this->addresses[] = ['ip' => $ip, 'hex' => Ip6Calc::hex($ip), 'node' => $node];
            if ($node !== null) {
                $this->nodes[$node]['addr'][] = count($this->addresses) - 1;
            }
        }
        foreach ($ranges as $r) {
            $node = $this->deepest($r['start']);
            $endHex = Ip6Calc::hex($r['end']);
            while ($node !== null && strcmp($this->nodes[$node]['end_hex'], $endHex) < 0) {
                $node = $this->nodes[$node]['parent'];
            }
            $this->ranges[$r['id']] = $r + ['node' => $node];
            if ($node !== null) {
                $this->nodes[$node]['ranges'][] = $r['id'];
            }
        }
        $this->computeStats();
    }

    private function addNode(\GMP $start, int $len, string $src, array $meta, bool $subnet): void
    {
        $b = Ip6Calc::block(Ip6Calc::network($start, $len), $len);
        $cidr = $b['cidr'];
        $this->nodes[$cidr] ??= $b + ['sources' => [], 'meta' => [], 'subnet' => false, 'parent' => null, 'children' => [], 'addr' => [], 'ranges' => []];
        $n = &$this->nodes[$cidr];
        if ($src !== 'virtual' || $n['sources'] === []) {
            $n['sources'][$src] = true;
        }
        if ($src !== 'virtual') {
            unset($n['sources']['virtual']);
        }
        $n['meta'] = $meta + $n['meta'];
        $n['subnet'] = $n['subnet'] || $subnet;
    }

    private function relink(): void
    {
        foreach ($this->nodes as &$n) {
            $n['parent'] = null;
            $n['children'] = [];
        }
        unset($n);
        $this->roots = [];

        $list = array_values($this->nodes);
        usort($list, fn ($a, $b) => [$a['start_hex'], $a['len']] <=> [$b['start_hex'], $b['len']]);
        $stack = [];
        foreach ($list as $n) {
            while ($stack !== [] && strcmp($this->nodes[end($stack)]['end_hex'], $n['start_hex']) < 0) {
                array_pop($stack);
            }
            if ($stack !== []) {
                $parent = end($stack);
                $this->nodes[$n['cidr']]['parent'] = $parent;
                $this->nodes[$parent]['children'][] = $n['cidr'];
            } else {
                $this->roots[] = $n['cidr'];
            }
            $stack[] = $n['cidr'];
        }
        $this->sorted = array_map(fn ($n) => $n['cidr'], $list);
    }

    /** Grupy /48 w korzeniu dla dłuższych prefiksów i adresów spoza prefiksów. */
    private function groupRoot(array $addresses): void
    {
        $groups = [];
        foreach ($this->roots as $c) {
            if ($this->nodes[$c]['len'] > self::ROOT_LEN) {
                $groups[Ip6Calc::hex(Ip6Calc::network($this->nodes[$c]['start'], self::ROOT_LEN))] = true;
            }
        }
        foreach ($addresses as $ip) {
            if ($this->deepest($ip) === null) {
                $groups[Ip6Calc::hex(Ip6Calc::network($ip, self::ROOT_LEN))] = true;
            }
        }
        if ($groups === []) {
            return;
        }
        foreach (array_keys($groups) as $hex) {
            $this->addNode(Ip6Calc::fromHex((string) $hex), self::ROOT_LEN, 'virtual', ['label' => 'poza znaną adresacją', 'outside' => true], false);
        }
        $this->relink();
    }

    private function inSubnet(string $cidr): bool
    {
        for ($p = $this->nodes[$cidr]['parent']; $p !== null; $p = $this->nodes[$p]['parent']) {
            if ($this->nodes[$p]['subnet'] && ! isset($this->nodes[$p]['sources']['virtual'])) {
                return true;
            }
        }

        return false;
    }

    private function inAggregate(string $cidr): bool
    {
        for ($p = $this->nodes[$cidr]['parent']; $p !== null; $p = $this->nodes[$p]['parent']) {
            if (! isset($this->nodes[$p]['sources']['virtual'])) {
                return true;
            }
        }

        return false;
    }

    /** Najgłębszy węzeł zawierający adres albo null. */
    public function deepest(\GMP $ip): ?string
    {
        $hex = Ip6Calc::hex($ip);
        $lo = 0;
        $hi = count($this->sorted) - 1;
        $best = -1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if (strcmp($this->nodes[$this->sorted[$mid]]['start_hex'], $hex) <= 0) {
                $best = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        $cidr = $best >= 0 ? $this->sorted[$best] : null;
        while ($cidr !== null && strcmp($this->nodes[$cidr]['end_hex'], $hex) < 0) {
            $cidr = $this->nodes[$cidr]['parent'];
        }

        return $cidr;
    }

    /** Czy liczymy w blokach /64 (prefiks szerszy niż /64). */
    public static function inUnits(array $n): bool
    {
        return $n['len'] < Ip6Calc::UNIT_LEN;
    }

    private function computeStats(): void
    {
        $order = array_keys($this->nodes);
        usort($order, fn ($a, $b) => $this->nodes[$b]['len'] <=> $this->nodes[$a]['len']);

        foreach ($order as $cidr) {
            $n = &$this->nodes[$cidr];
            $busy = [];
            $addrTotal = count($n['addr']);
            foreach ($n['children'] as $c) {
                $child = $this->nodes[$c];
                // Wirtualne dziecko jest przezroczyste - liczy się to, co w nim leży.
                if (isset($child['sources']['virtual'])) {
                    array_push($busy, ...$child['occ']);
                } else {
                    $busy[] = [$child['start'], $child['end']];
                }
                $addrTotal += $child['stats']['addresses'];
            }
            foreach ($n['addr'] as $i) {
                $busy[] = [$this->addresses[$i]['ip'], $this->addresses[$i]['ip']];
            }
            array_push($busy, ...$this->rangesIn($n));
            $n['occ'] = Ip6Calc::merge($busy);

            $units = self::inUnits($n);
            $occ = $units ? Ip6Calc::roundOut($n['occ'], Ip6Calc::UNIT_LEN) : $n['occ'];
            $free = Ip6Calc::freeIntervals($n['start'], $n['end'], $occ);
            $div = $units ? Ip6Calc::size(Ip6Calc::UNIT_LEN) : gmp_init(1);
            $capacity = gmp_div_q(Ip6Calc::size($n['len']), $div);
            $used = gmp_div_q(Ip6Calc::count($occ), $div);

            $n['stats'] = [
                'unit' => $units ? '/64' : 'adr.',
                'capacity' => gmp_strval($capacity),
                'used' => gmp_strval($used),
                'free' => gmp_strval(gmp_sub($capacity, $used)),
                'util' => gmp_cmp($capacity, 0) > 0 ? round(gmp_intval(gmp_div_q(gmp_mul($used, 1000), $capacity)) / 10, 1) : 100.0,
                'addresses' => $addrTotal,
            ];
            $n['free_intervals'] = $free;
            unset($n);
        }
    }

    /** @return list<string> */
    public function roots(): array
    {
        return $this->roots;
    }

    public function node(string $cidr): ?array
    {
        return $this->nodes[$cidr] ?? null;
    }

    /** @return array<string,array> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /** @return array{ip:\GMP,hex:string,node:?string} */
    public function address(int $i): array
    {
        return $this->addresses[$i];
    }

    /** @return list<int> adresy bez węzła */
    public function rootAddresses(): array
    {
        return array_keys(array_filter($this->addresses, fn ($a) => $a['node'] === null));
    }

    /** @return list<string> od korzenia do węzła */
    public function path(string $cidr): array
    {
        $out = [];
        for ($c = $cidr; $c !== null; $c = $this->nodes[$c]['parent']) {
            array_unshift($out, $c);
        }

        return $out;
    }

    /**
     * Wolne miejsce między dziećmi węzła (dziecko liczy się w całości), w /64 dla
     * prefiksów szerszych niż /64.
     *
     * @return list<array{0:\GMP,1:\GMP}>
     */
    public function gaps(string $cidr): array
    {
        $n = $this->nodes[$cidr] ?? null;
        if ($n === null) {
            return [];
        }
        $busy = [];
        foreach ($n['children'] as $c) {
            $busy[] = [$this->nodes[$c]['start'], $this->nodes[$c]['end']];
        }
        foreach ($n['addr'] as $i) {
            $busy[] = [$this->addresses[$i]['ip'], $this->addresses[$i]['ip']];
        }
        array_push($busy, ...$this->rangesIn($n));
        if (self::inUnits($n)) {
            $busy = Ip6Calc::roundOut($busy, Ip6Calc::UNIT_LEN);
        }

        return Ip6Calc::freeIntervals($n['start'], $n['end'], $busy);
    }

    /**
     * Największe wolne bloki w węźle (najkrótsze maski najpierw).
     *
     * @return list<array{cidr:string,len:int}>
     */
    public function freeBlocks(string $cidr, int $limit = 20): array
    {
        $n = $this->nodes[$cidr] ?? null;
        if ($n === null) {
            return [];
        }
        $blocks = [];
        foreach ($n['free_intervals'] as [$a, $b]) {
            foreach (Ip6Calc::rangeToCidrs($a, $b, 64) as $blk) {
                $blocks[] = ['cidr' => Ip6Calc::cidr($blk['start'], $blk['len']), 'len' => $blk['len'], 'hex' => Ip6Calc::hex($blk['start'])];
            }
        }
        usort($blocks, fn ($x, $y) => [$x['len'], $x['hex']] <=> [$y['len'], $y['hex']]);

        return array_map(fn ($b) => ['cidr' => $b['cidr'], 'len' => $b['len']], array_slice($blocks, 0, $limit));
    }

    /**
     * Zakresy nachodzące na węzeł, przycięte do niego.
     *
     * @return list<array{0:\GMP,1:\GMP}>
     */
    private function rangesIn(array $n): array
    {
        $out = [];
        foreach ($this->ranges as $r) {
            if (gmp_cmp($r['end'], $n['start']) >= 0 && gmp_cmp($r['start'], $n['end']) <= 0) {
                $out[] = [gmp_cmp($r['start'], $n['start']) > 0 ? $r['start'] : $n['start'], gmp_cmp($r['end'], $n['end']) < 0 ? $r['end'] : $n['end']];
            }
        }

        return $out;
    }

    /** @return array{id:int,start:\GMP,end:\GMP,node:?string}|null */
    public function range(int $id): ?array
    {
        return $this->ranges[$id] ?? null;
    }

    public static function isVirtual(array $n): bool
    {
        return isset($n['sources']['virtual']);
    }

    public static function isOutside(array $n): bool
    {
        return ! empty($n['meta']['outside']);
    }
}
