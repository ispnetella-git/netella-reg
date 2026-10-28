<?php

/*
 * IpTree.php - drzewo prefiksów IPv4 z zajętością i wolnymi blokami.
 *
 * Węzły:
 *   - sieci z LibreNMS (adres + maska interfejsu, np. 45.10.136.0/24),
 *   - prefiksy ręczne (agregat, pula, rezerwacja, sieć),
 *   - adresacja wewnętrzna (src 'implicit': RFC 1918, CGNAT - ustawienie
 *     "Adresacja wewnętrzna"), traktowana jak agregat,
 *   - węzły wirtualne na poziomach grupowania (domyślnie /8, /16, /24), gdy
 *     nie ma tam prawdziwego prefiksu - dzięki nim drzewo rozwija się
 *     klasa po klasie. Tylko wewnątrz prawdziwego prefiksu, który nie jest
 *     siecią (agregat, adresacja wewnętrzna): korzeniami drzewa są najszersze
 *     prawdziwe prefiksy, a nie np. 45.0.0.0/8, którego nie mamy.
 * W korzeniu nie ma masek dłuższych niż /24 (ROOT_LEN): sieci, adresy i zakresy
 * spoza prawdziwych prefiksów (np. 91.225.156.80/29, loopback /32) trafiają do
 * wirtualnej grupy /24 z meta 'outside'. Taka grupa nie ma wolnego miejsca -
 * reszta tej /24 nie musi być nasza. Wolnego miejsca w korzeniu nie pokazujemy.
 * Prefiksy CIDR nigdy nie nachodzą na siebie częściowo, więc rodzic węzła to
 * najkrótszy... a dokładniej najbliższy (najdłuższy) prefiks, który go zawiera.
 *
 * Adres trafia do najgłębszego węzła, który go zawiera.
 *
 * Zakresy ręczne (pule a-b) nie są węzłami: należą do najgłębszego węzła,
 * który zawiera je w całości ('ranges'), a w zajętości i wolnym miejscu
 * liczą się w całości, tak jak prefiks. Zakres jest też kotwicą poziomów
 * grupowania, w które mieści się w całości.
 *
 * Zajętość węzła: suma zakresów dzieci i adresów leżących bezpośrednio w nim.
 * Dla sieci (podsieć interfejsu, sieć ręczna, pula) liczymy hosty: bez adresu
 * sieci i rozgłoszeniowego dla /30 i krótszych.
 *
 * Czyste, bez Laravela.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Ipam;

final class IpTree
{
    /** Najdłuższa maska węzła w korzeniu drzewa (drobniejsze grupujemy w /24). */
    public const ROOT_LEN = 24;

    /** @var array<string,array> cidr => węzeł */
    private array $nodes = [];

    /** @var list<array> adresy z polem 'node' */
    private array $addresses = [];

    /** @var list<string> węzły bez rodzica */
    private array $roots = [];

    /** @var array<int,array{id:int,start:int,end:int,node:?string}> zakresy ręczne */
    private array $ranges = [];

    /**
     * @param  list<array{start:int,len:int,src:string,meta?:array,subnet?:bool}>  $prefixes
     * @param  list<array{ip:int}>  $addresses
     * @param  list<int>  $levels  poziomy grupowania, np. [8, 16, 24]
     * @param  list<array{id:int,start:int,end:int}>  $ranges  zakresy ręczne
     */
    public function __construct(array $prefixes, array $addresses, array $levels = [8, 16, 24], array $ranges = [])
    {
        foreach ($prefixes as $p) {
            $this->addNode($p['start'], $p['len'], $p['src'], $p['meta'] ?? [], (bool) ($p['subnet'] ?? false));
        }

        // Węzły wirtualne na poziomach grupowania - nad prefiksami i adresami.
        sort($levels);
        $anchors = array_merge(
            array_map(fn ($n) => [$n['start'], $n['len']], array_values($this->nodes)),
            array_map(fn ($a) => [$a['ip'], 32], $addresses),
            // Zakres: poziomy, w których mieści się w całości (wspólny początek adresów).
            array_map(fn ($r) => [$r['start'], self::commonLen($r['start'], $r['end']) + 1], $ranges)
        );
        foreach ($anchors as [$start, $len]) {
            foreach ($levels as $lvl) {
                if ($lvl < $len) {
                    $this->addNode(IpCalc::network($start, $lvl), $lvl, 'virtual', [], false);
                }
            }
        }

        $this->linkParents();

        // Poziomy grupowania zostają tylko w agregatach. Wewnątrz prawdziwej sieci (np. /24
        // w sieci 172.19.0.0/16) adres wyglądałby na leżący poza siecią, a nad prefiksami
        // (np. 45.0.0.0/8 nad 45.10.136.0/24) sugerowałyby, że cała klasa jest nasza.
        // Usuwamy je i łączymy drzewo od nowa.
        $drop = array_filter(array_keys($this->nodes), fn ($c) => isset($this->nodes[$c]['sources']['virtual'])
            && ($this->inSubnet($c) || ! $this->inAggregate($c)));
        if ($drop !== []) {
            foreach ($drop as $c) {
                unset($this->nodes[$c]);
            }
            foreach ($this->nodes as &$n) {
                $n['parent'] = null;
                $n['children'] = [];
            }
            unset($n);
            $this->roots = [];
            $this->linkParents();
        }

        $this->groupRoot($addresses, $ranges);
        $this->placeAddresses($addresses);
        $this->placeRanges($ranges);
        $this->computeStats();
    }

    /**
     * Grupy /24 w korzeniu dla prefiksów dłuższych niż /24, adresów i zakresów
     * spoza wszystkich prefiksów, żeby korzeń nie był długą listą /29-/32.
     */
    private function groupRoot(array $addresses, array $ranges): void
    {
        $groups = [];
        foreach ($this->roots as $c) {
            if ($this->nodes[$c]['len'] > self::ROOT_LEN) {
                $groups[IpCalc::network($this->nodes[$c]['start'], self::ROOT_LEN)] = true;
            }
        }
        foreach ($addresses as $a) {
            if ($this->deepest($a['ip']) === null) {
                $groups[IpCalc::network($a['ip'], self::ROOT_LEN)] = true;
            }
        }
        foreach ($ranges as $r) {
            $g = IpCalc::network($r['start'], self::ROOT_LEN);
            if ($g === IpCalc::network($r['end'], self::ROOT_LEN) && $this->container($r['start'], $r['end']) === null) {
                $groups[$g] = true;
            }
        }
        if ($groups === []) {
            return;
        }
        foreach (array_keys($groups) as $g) {
            $this->addNode($g, self::ROOT_LEN, 'virtual', ['label' => 'poza znaną adresacją', 'outside' => true], false);
        }
        foreach ($this->nodes as &$n) {
            $n['parent'] = null;
            $n['children'] = [];
        }
        unset($n);
        $this->roots = [];
        $this->linkParents();
    }

    /** Najgłębszy węzeł zawierający cały przedział albo null. */
    private function container(int $start, int $end): ?string
    {
        $node = $this->deepest($start);
        while ($node !== null && $this->nodes[$node]['end'] < $end) {
            $node = $this->nodes[$node]['parent'];
        }

        return $node;
    }

    /** Czy węzeł to grupa /24 spoza znanej adresacji (bez wolnego miejsca). */
    public static function isOutside(array $node): bool
    {
        return ! empty($node['meta']['outside']);
    }

    /** Liczba wspólnych początkowych bitów dwóch adresów. */
    private static function commonLen(int $a, int $b): int
    {
        $x = $a ^ $b;
        $n = 32;
        while ($x > 0) {
            $x >>= 1;
            $n--;
        }

        return $n;
    }

    private function placeRanges(array $ranges): void
    {
        foreach ($ranges as $r) {
            $node = $this->container($r['start'], $r['end']);
            $this->ranges[$r['id']] = $r + ['node' => $node];
            if ($node !== null) {
                $this->nodes[$node]['ranges'][] = $r['id'];
            }
        }
    }

    /**
     * Zakresy nachodzące na węzeł, przycięte do niego.
     *
     * @return list<array{0:int,1:int}>
     */
    private function rangesIn(array $n): array
    {
        $out = [];
        foreach ($this->ranges as $r) {
            if ($r['end'] >= $n['start'] && $r['start'] <= $n['end']) {
                $out[] = [max($r['start'], $n['start']), min($r['end'], $n['end'])];
            }
        }

        return $out;
    }

    /** @return array{id:int,start:int,end:int,node:?string}|null */
    public function range(int $id): ?array
    {
        return $this->ranges[$id] ?? null;
    }

    /** @return list<int> indeksy adresów poza wszystkimi prefiksami */
    public function rootAddresses(): array
    {
        return array_keys(array_filter($this->addresses, fn ($a) => $a['node'] === null));
    }

    /** Czy adres leży w prawdziwej sieci (podsieć interfejsu, sieć używana, pula). */
    public function inNetwork(int $ip): bool
    {
        for ($c = $this->deepest($ip); $c !== null; $c = $this->nodes[$c]['parent']) {
            if ($this->nodes[$c]['subnet'] && ! isset($this->nodes[$c]['sources']['virtual'])) {
                return true;
            }
        }

        return false;
    }

    /** Najgłębszy prawdziwy (nie wirtualny) węzeł zawierający cały przedział albo null. */
    public function realContainer(int $start, int $end): ?string
    {
        for ($c = $this->deepest($start); $c !== null; $c = $this->nodes[$c]['parent']) {
            $n = $this->nodes[$c];
            if (! isset($n['sources']['virtual']) && $n['end'] >= $end) {
                return $c;
            }
        }

        return null;
    }

    /** @return list<int> zakresy bez węzła (poza wszystkimi prefiksami) */
    public function rootRanges(): array
    {
        return array_keys(array_filter($this->ranges, fn ($r) => $r['node'] === null));
    }

    /** Czy węzeł leży wewnątrz prawdziwej sieci (podsieć interfejsu, sieć używana, pula). */
    private function inSubnet(string $cidr): bool
    {
        for ($p = $this->nodes[$cidr]['parent']; $p !== null; $p = $this->nodes[$p]['parent']) {
            if ($this->nodes[$p]['subnet'] && ! isset($this->nodes[$p]['sources']['virtual'])) {
                return true;
            }
        }

        return false;
    }

    /** Czy węzeł leży wewnątrz prawdziwego prefiksu (agregat, adresacja wewnętrzna). */
    private function inAggregate(string $cidr): bool
    {
        for ($p = $this->nodes[$cidr]['parent']; $p !== null; $p = $this->nodes[$p]['parent']) {
            if (! isset($this->nodes[$p]['sources']['virtual'])) {
                return true;
            }
        }

        return false;
    }

    private function addNode(int $start, int $len, string $src, array $meta, bool $subnet): void
    {
        $start = IpCalc::network($start, $len);
        $cidr = IpCalc::cidr($start, $len);

        if (! isset($this->nodes[$cidr])) {
            $this->nodes[$cidr] = [
                'cidr' => $cidr, 'start' => $start, 'end' => $start + IpCalc::size($len) - 1, 'len' => $len,
                'sources' => [], 'meta' => [], 'subnet' => false, 'parent' => null, 'children' => [], 'addr' => [], 'ranges' => [],
            ];
        }

        $n = &$this->nodes[$cidr];
        if ($src !== 'virtual' || $n['sources'] === []) {
            $n['sources'][$src] = true;
        }
        // Prawdziwy prefiks zastępuje wirtualny w tym samym miejscu.
        if ($src !== 'virtual') {
            unset($n['sources']['virtual']);
        }
        $n['meta'] = array_merge_recursive($n['meta'], $meta);
        $n['subnet'] = $n['subnet'] || $subnet;
    }

    private function linkParents(): void
    {
        $list = array_values($this->nodes);
        usort($list, fn ($a, $b) => [$a['start'], $a['len']] <=> [$b['start'], $b['len']]);

        $stack = [];
        foreach ($list as $n) {
            while ($stack !== [] && $this->nodes[end($stack)]['end'] < $n['start']) {
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

        // Kolejność wg sortowania: dzieci są już po kolei.
        $this->sorted = array_map(fn ($n) => $n['cidr'], $list);
    }

    /** @var list<string> węzły posortowane (start, len) - do wyszukiwania binarnego */
    private array $sorted = [];

    private function placeAddresses(array $addresses): void
    {
        foreach ($addresses as $i => $a) {
            $node = $this->deepest($a['ip']);
            $a['node'] = $node;
            $this->addresses[$i] = $a;
            if ($node !== null) {
                $this->nodes[$node]['addr'][] = $i;
            }
        }
    }

    /** Najgłębszy węzeł zawierający adres albo null. */
    public function deepest(int $ip): ?string
    {
        // Ostatni węzeł z start <= ip, potem w górę po rodzicach.
        $lo = 0;
        $hi = count($this->sorted) - 1;
        $best = -1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($this->nodes[$this->sorted[$mid]]['start'] <= $ip) {
                $best = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        $cidr = $best >= 0 ? $this->sorted[$best] : null;
        while ($cidr !== null && $this->nodes[$cidr]['end'] < $ip) {
            $cidr = $this->nodes[$cidr]['parent'];
        }

        return $cidr;
    }

    private function computeStats(): void
    {
        // Od najdłuższych masek: dzieci są policzone przed rodzicem.
        $order = array_keys($this->nodes);
        usort($order, fn ($a, $b) => $this->nodes[$b]['len'] <=> $this->nodes[$a]['len']);

        foreach ($order as $cidr) {
            $n = &$this->nodes[$cidr];
            $busy = [];
            $ips = [];
            foreach ($n['children'] as $c) {
                $child = $this->nodes[$c];
                // Prawdziwy prefiks zajmuje cały swój zakres. Wirtualny jest
                // przezroczysty: liczy się tylko to, co jest w nim naprawdę.
                if (isset($child['sources']['virtual'])) {
                    array_push($busy, ...$child['occ']);
                } else {
                    $busy[] = [$child['start'], $child['end']];
                }
            }
            foreach ($n['addr'] as $i) {
                $ips[$this->addresses[$i]['ip']] = true;
            }
            foreach (array_keys($ips) as $ip) {
                $busy[] = [$ip, $ip];
            }
            array_push($busy, ...$this->rangesIn($n));
            $busy = IpCalc::merge($busy);
            $n['occ'] = $busy;

            $addrTotal = count($ips);
            foreach ($n['children'] as $c) {
                $addrTotal += $this->nodes[$c]['stats']['addresses'];
            }

            // W sieci adres sieci i rozgłoszeniowy nie są do wzięcia.
            [$fs, $fe] = $n['subnet'] && $n['len'] <= 30 ? [$n['start'] + 1, $n['end'] - 1] : [$n['start'], $n['end']];
            $capacity = $fe - $fs + 1;
            $free = IpCalc::freeIntervals($fs, $fe, $busy);
            $freeCount = 0;
            foreach ($free as [$a, $b]) {
                $freeCount += $b - $a + 1;
            }

            $n['stats'] = [
                'size' => IpCalc::size($n['len']),
                'capacity' => $capacity,
                'addresses' => $addrTotal,
                'free' => $freeCount,
                'used' => $capacity - $freeCount,
                'util' => $capacity > 0 ? round(($capacity - $freeCount) * 100 / $capacity, 1) : 100.0,
            ];
            $n['free_intervals'] = $free;
            if (self::isOutside($n)) {
                // Grupa spoza znanej adresacji: tylko to, co w niej jest, bez wolnego miejsca.
                $used = $capacity - $freeCount;
                $n['stats'] = ['size' => $n['stats']['size'], 'capacity' => $used, 'addresses' => $addrTotal, 'free' => 0, 'used' => $used, 'util' => null];
                $n['free_intervals'] = [];
            }
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

    public function address(int $i): array
    {
        return $this->addresses[$i];
    }

    /** Ścieżka od korzenia do węzła (do rozwinięcia drzewa po wyszukaniu). */
    public function path(string $cidr): array
    {
        $out = [];
        for ($c = $cidr; $c !== null; $c = $this->nodes[$c]['parent'] ?? null) {
            array_unshift($out, $c);
        }

        return $out;
    }

    /**
     * Wolne bloki węzła jako CIDR, największe najpierw.
     *
     * @return list<array{cidr:string,len:int,size:int}>
     */
    public function freeBlocks(string $cidr, int $limit = 20): array
    {
        $n = $this->nodes[$cidr] ?? null;
        if ($n === null) {
            return [];
        }
        $blocks = [];
        foreach ($n['free_intervals'] as [$a, $b]) {
            foreach (IpCalc::rangeToCidrs($a, $b) as $blk) {
                $blocks[] = ['cidr' => IpCalc::cidr($blk['start'], $blk['len']), 'len' => $blk['len'], 'size' => IpCalc::size($blk['len']), 'start' => $blk['start']];
            }
        }
        usort($blocks, fn ($x, $y) => [$x['len'], $x['start']] <=> [$y['len'], $y['start']]);

        return array_slice($blocks, 0, $limit);
    }

    /**
     * Zajęte przedziały w węźle (dzieci i adresy) - do szukania wolnych bloków.
     *
     * @return list<array{0:int,1:int}>
     */
    public function busyIntervals(string $cidr): array
    {
        return $this->nodes[$cidr]['occ'] ?? [];
    }

    /**
     * Wolne miejsce do pokazania w drzewie między dziećmi węzła. Inaczej niż przy
     * zajętości, dziecko wirtualne liczy się tu w całości: jego wolne miejsce
     * widać po rozwinięciu go, a w rodzicu wolne bloki nie mogą na nie nachodzić.
     * W sieci pomijamy adres sieci i rozgłoszeniowy.
     *
     * @return list<array{0:int,1:int}>
     */
    public function gaps(string $cidr): array
    {
        $n = $this->nodes[$cidr] ?? null;
        if ($n === null || self::isOutside($n)) {
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
        [$fs, $fe] = $n['subnet'] && $n['len'] <= 30 ? [$n['start'] + 1, $n['end'] - 1] : [$n['start'], $n['end']];

        return IpCalc::freeIntervals($fs, $fe, IpCalc::merge($busy));
    }

    /** Czy węzeł jest tylko poziomem grupowania (bez prawdziwego prefiksu). */
    public static function isVirtual(array $node): bool
    {
        return isset($node['sources']['virtual']);
    }
}
