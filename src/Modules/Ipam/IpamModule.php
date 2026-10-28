<?php

/*
 * IpamModule.php - moduł adresacji IP.
 *
 * Dane z LibreNMS (adres, maska, host, interfejs) przez migawkę i wpisy
 * ręczne (IpamManual) w przestrzeniach adresowych (IpamSpaces). Drzewo IPv4
 * grupowane według długości maski, zajętość, wolne bloki, wyszukiwanie,
 * uwagi (ten sam adres na wielu urządzeniach, podejrzane maski).
 *
 * Zakresy ręczne (pule a-b) są w drzewie wierszami "range:<id>" między
 * dziećmi węzła; rozwinięty zakres pokazuje swoje adresy i wolne miejsce.
 *
 * Import z RIPE (ripe_lookup, ripe_import): prefiksy numeru AS z RIPEstat
 * i bazy RIPE, użytkownik wybiera, które dodać jako agregaty.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Ipam;

use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Ispnetella\NetellaReg\Core\Access;
use Ispnetella\NetellaReg\Core\ApiException;
use Ispnetella\NetellaReg\Core\AuditLog;
use Ispnetella\NetellaReg\Core\Db;
use Ispnetella\NetellaReg\Core\Expiry;
use Ispnetella\NetellaReg\Core\Settings;
use Ispnetella\NetellaReg\Core\Snapshot;
use Ispnetella\NetellaReg\Core\SyncContext;
use Ispnetella\NetellaReg\Core\SyncRunner;
use Ispnetella\NetellaReg\Modules\AbstractModule;

final class IpamModule extends AbstractModule
{
    public const KEY = 'ipam';

    /** Typ rekordu w migawce: adres na porcie. */
    public const TYPE = 'address';

    /** Najwięcej adresów w jednej odpowiedzi drzewa. */
    private const MAX_ADDR = 1024;

    /** Pola formularza wpisu ręcznego. */
    private const FORM_FIELDS = ['role', 'name', 'description', 'owner', 'vlan', 'devices', Expiry::FIELD];

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Adresacja IP';
    }

    public function schemaVersion(): int
    {
        return 3;
    }

    public function migrate(Connection $db, int $from): void
    {
        if ($from < 1) {
            IpamSpaces::migrate($db);
            IpamManual::migrate($db);
        }
        if ($from < 2) {
            IpamManual::migrateExpiry($db);
        }
        if ($from < 3) {
            IpamManual::migrateRanges($db);
        }
    }

    public function sync(SyncContext $ctx): array
    {
        $collector = new IpamCollector(
            IpamCollector::parseIgnore((string) Settings::get('ipam_ignore', '')),
            trim((string) Settings::get('ipam_ignore_if', ''))
        );
        $current = $collector->collect();
        $ctx->log(count($current) . ' adresów w LibreNMS (po pominięciu ignorowanych)');

        // Zakres = adres IPv4 jako liczba: historia sieci znajdzie zdarzenia jej adresów.
        $summary = $ctx->snapshot->apply(self::KEY, self::TYPE, $current, $ctx->now, function (string $key, array $d) {
            $ip = ($d['family'] ?? 4) === 4 ? IpCalc::ip2int((string) $d['ip']) : null;

            return $ip === null ? null : [$ip, $ip];
        });

        $indexes = [];
        foreach (array_keys($this->spaces()->all()) as $space) {
            $indexes[$space] = $this->index($space, $ctx->now);
        }
        $summary['adopted'] = $this->manual()->adopt($indexes, $ctx->now);
        $summary['expired'] = $this->manual()->expire($ctx->now);

        return $summary;
    }

    public function apiPermission(string $action, string $method): string
    {
        return match ($action) {
            'space_create' => Access::ADMIN,
            'manual_delete' => Access::DELETE,
            'manual_create', 'manual_update', 'manual_to_range', 'ripe_lookup', 'ripe_import' => Access::EDIT,
            default => Access::VIEW,
        };
    }

    public function tabView(): ?string
    {
        return 'modules.ipam.tab';
    }

    public function api(string $action, Request $request): array
    {
        $space = max(1, (int) $request->input('space', IpamSpaces::GLOBAL_ID));

        return match ($action) {
            'spaces' => ['spaces' => array_values($this->spaces()->all())],
            'space_create' => $this->post($request) ?? ['space' => $this->spaces()->create($request->only(['name', 'description', 'vrf']))],
            'tree' => $this->apiTree($space, trim((string) $request->query('node', ''))),
            'node' => $this->apiNode($space, trim((string) $request->query('cidr', ''))),
            'range' => $this->apiRange($space, (int) $request->query('id', 0)),
            'search' => $this->apiSearch($space, trim((string) $request->query('q', ''))),
            'free' => $this->apiFree($space, trim((string) $request->query('within', '')), (int) $request->query('len', 29),
                (int) $request->query('count', 5)),
            'issues' => $this->apiIssues($space),
            'manual_list' => $this->apiManualList($space),
            'manual_create' => $this->post($request) ?? $this->apiManualCreate($space, $request),
            'manual_update' => $this->post($request) ?? $this->apiManualUpdate($request),
            'manual_delete' => $this->post($request) ?? $this->apiManualDelete($request),
            'manual_to_range' => $this->post($request) ?? $this->apiManualToRange($request),
            'ripe_lookup' => $this->apiRipeLookup($space, trim((string) $request->query('asn', ''))),
            'ripe_import' => $this->post($request) ?? $this->apiRipeImport($space, $request),
            default => parent::api($action, $request),
        };
    }

    public function deviceOverview(int $deviceId): ?array
    {
        $rows = (new Snapshot(Db::connection()))->rows(self::KEY, self::TYPE, function ($q) {
            $q->whereNull('gone_at');
        });
        $rows = array_values(array_filter($rows, fn ($r) => (int) ($r['data']['device_id'] ?? 0) === $deviceId));
        if ($rows === []) {
            return null;
        }
        usort($rows, fn ($a, $b) => [$a['data']['family'], IpCalc::ip2int((string) $a['data']['ip']) ?? 0]
            <=> [$b['data']['family'], IpCalc::ip2int((string) $b['data']['ip']) ?? 0]);

        return ['view' => 'modules.ipam.device-overview', 'data' => ['ipRows' => $rows]];
    }

    // ---------------------------------------------------------------- pomocnicze

    /** null przy POST, wyjątek przy innej metodzie - do użycia z ?? w match. */
    private function post(Request $request): ?array
    {
        if (! $request->isMethod('POST')) {
            throw new ApiException('Wymagany POST.', 405);
        }

        return null;
    }

    private function spaces(): IpamSpaces
    {
        return new IpamSpaces(Db::connection());
    }

    private function manual(): IpamManual
    {
        return new IpamManual(Db::connection());
    }

    /** @return list<int> */
    private static function levels(): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', (string) Settings::get('ipam_levels', '8,16,24'), -1, PREG_SPLIT_NO_EMPTY) as $l) {
            if (ctype_digit($l) && (int) $l >= 1 && (int) $l <= 31) {
                $out[(int) $l] = (int) $l;
            }
        }

        return array_values($out) ?: [8, 16, 24];
    }

    /** @return list<string> adresacja wewnętrzna z ustawienia (poprawne prefiksy) */
    private static function implicitPrefixes(): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', (string) Settings::get('ipam_private', ''), -1, PREG_SPLIT_NO_EMPTY) as $p) {
            try {
                $c = IpCalc::parseV4($p, true);
                if ($c['len'] < 32) {
                    $out[$c['cidr']] = $c['cidr'];
                }
            } catch (\InvalidArgumentException) {
            }
        }

        return array_values($out);
    }

    private function index(int $space, ?\DateTimeImmutable $now = null): IpamIndex
    {
        $spaces = $this->spaces()->all();
        if (! isset($spaces[$space])) {
            throw new ApiException('Nie ma takiej przestrzeni adresowej.', 404);
        }

        return new IpamIndex(
            (new Snapshot(Db::connection()))->rows(self::KEY, self::TYPE),
            $this->manual()->active($space),
            $space,
            $spaces,
            $now ?? new \DateTimeImmutable(),
            Settings::graceDays(),
            Settings::disabledMode(),
            self::levels(),
            self::implicitPrefixes()
        );
    }

    private static function comment(Request $request): ?string
    {
        $c = trim((string) $request->input('comment', ''));

        return $c === '' ? null : mb_substr($c, 0, 1000);
    }

    /** Węzeł drzewa do JSON-a. */
    private function nodeInfo(IpamIndex $idx, array $n): array
    {
        $lnms = [];
        $vlans = [];
        foreach ($idx->networks()[$n['cidr']] ?? [] as $r) {
            $d = $r['data'];
            $lnms[] = ['device' => $d['device'], 'device_id' => $d['device_id'], 'if' => $d['if'], 'alias' => $d['alias'],
                'ip' => $d['ip'], 'state' => $r['state'], 'disabled' => $d['disabled']];
            if ($d['vlan']) {
                $vlans[$d['vlan']] = true;
            }
        }
        $manual = $idx->manualPrefixes()[$n['cidr']] ?? null;
        if ($manual && $manual['vlan']) {
            $vlans[$manual['vlan']] = true;
        }

        // Etykieta: wpis ręczny, potem opisy interfejsów z LibreNMS, potem adresacja wewnętrzna.
        $label = $manual ? ($manual['name'] ?: $manual['description']) : null;
        if ($label === null && $lnms !== []) {
            $label = implode(', ', array_slice(array_values(array_unique(array_filter(array_map(
                fn ($l) => $l['alias'] ?: ($l['device'] . ' ' . $l['if']), $lnms)))), 0, 3));
        }
        if ($label === null && isset($n['meta']['label'])) {
            $label = (array) $n['meta']['label'];
            $label = $label[0];
        }

        $active = array_filter($lnms, fn ($l) => $l['state'] === IpamIndex::LNMS);

        return [
            'cidr' => $n['cidr'],
            'n' => $n['start'],
            'len' => $n['len'],
            'first' => IpCalc::int2ip($n['start']),
            'last' => IpCalc::int2ip($n['end']),
            'virtual' => IpTree::isVirtual($n),
            'sources' => array_keys($n['sources']),
            'subnet' => $n['subnet'],
            'state' => $lnms === [] ? null : ($active !== [] ? IpamIndex::LNMS : $lnms[0]['state']),
            'stats' => $n['stats'],
            'children' => count($n['children']),
            'direct' => count($n['addr']),
            'label' => $label,
            'lnms' => $lnms,
            'manual' => $manual,
            'vlans' => array_keys($vlans),
        ];
    }

    private function addrInfo(IpamIndex $idx, int $ip, array $dups): array
    {
        $a = $idx->address($ip);
        $rows = [];
        foreach ($a['rows'] ?? [] as $r) {
            $d = $r['data'];
            $rows[] = ['device' => $d['device'], 'device_id' => $d['device_id'], 'if' => $d['if'], 'alias' => $d['alias'],
                'len' => $d['len'], 'vlan' => $d['vlan'], 'disabled' => $d['disabled'], 'state' => $r['state'],
                'first_seen' => $r['first_seen'], 'last_seen' => $r['last_seen'], 'gone_at' => $r['gone_at']];
        }

        return [
            'ip' => IpCalc::int2ip($ip),
            'n' => $ip,
            'state' => $idx->status($ip),
            'rows' => $rows,
            'manual' => $a['manual'] ?? null,
            'dup' => isset($dups[$ip]),
            'range' => ($r = $idx->rangeOf($ip)) ? ['id' => $r['id'], 'label' => $r['cidr'], 'role_label' => $r['role_label'],
                'name' => $r['name'] ?: $r['description']] : null,
        ];
    }

    /** Zakres ręczny jako wiersz drzewa (ten sam kształt co węzeł). */
    private function rangeInfo(IpamIndex $idx, array $m): array
    {
        $size = $m['end'] - $m['start'] + 1;
        $used = count($idx->addressesIn($m['start'], $m['end']));

        return [
            'cidr' => 'range:' . $m['id'], 'range' => true, 'id' => $m['id'], 'n' => $m['start'], 'len' => 32,
            'first' => $m['first'], 'last' => $m['last'], 'virtual' => false, 'sources' => ['manual'], 'subnet' => false,
            'state' => null,
            'stats' => ['size' => $size, 'capacity' => $size, 'addresses' => $used, 'free' => $size - $used, 'used' => $used,
                'util' => round($used * 100 / $size, 1)],
            'children' => 0, 'direct' => $used,
            'label' => $m['name'] ?: $m['description'], 'lnms' => [], 'manual' => $m, 'vlans' => $m['vlan'] ? [$m['vlan']] : [],
        ];
    }

    private function findRange(IpamIndex $idx, int $id): array
    {
        $m = $idx->ranges()[$id] ?? null;
        if ($m === null) {
            throw new ApiException('Nie ma takiego zakresu w tej przestrzeni (mógł zostać usunięty).', 404);
        }

        return $m;
    }

    /** Ścieżka w drzewie do zakresu (węzeł, który go zawiera, i sam zakres). */
    private function rangePath(IpTree $tree, int $id): array
    {
        $node = $tree->range($id)['node'] ?? null;

        return array_merge($node ? $tree->path($node) : [], ['range:' . $id]);
    }

    // ---------------------------------------------------------------- API

    private function apiTree(int $space, string $node): array
    {
        $idx = $this->index($space);
        $tree = $idx->tree();
        $dups = $idx->duplicates();

        if (str_starts_with($node, 'range:')) {
            return $this->apiRangeTree($idx, (int) substr($node, 6));
        }

        if ($node === '') {
            // Korzeń: najszersze prawdziwe prefiksy i adresy poza wszystkimi prefiksami.
            $children = $tree->roots();
            $addr = $tree->rootAddresses();
            $parent = null;
            $rangeIds = $tree->rootRanges();
        } else {
            $n = $tree->node($node);
            if ($n === null) {
                throw new ApiException("Nie ma węzła {$node}.", 404);
            }
            $children = $n['children'];
            $addr = $n['addr'];
            $parent = $this->nodeInfo($idx, $n);
            $rangeIds = $n['ranges'];
        }

        // Adresy w zakresach są widoczne po rozwinięciu zakresu.
        $ranges = array_map(fn ($id) => $idx->ranges()[$id], $rangeIds);
        $ips = array_unique(array_map(fn ($i) => $tree->address($i)['ip'], $addr));
        $ips = array_filter($ips, function ($ip) use ($ranges) {
            foreach ($ranges as $r) {
                if ($ip >= $r['start'] && $ip <= $r['end']) {
                    return false;
                }
            }

            return true;
        });
        sort($ips);

        return [
            'node' => $parent,
            'children' => array_merge(array_map(fn ($c) => $this->nodeInfo($idx, $tree->node($c)), $children),
                array_map(fn ($r) => $this->rangeInfo($idx, $r), $ranges)),
            'addresses' => array_map(fn ($ip) => $this->addrInfo($idx, $ip, $dups), array_slice($ips, 0, self::MAX_ADDR)),
            'addresses_total' => count($ips),
            'free' => $node === '' ? [] : $this->treeFree($tree, $node),
            'labels' => IpamIndex::LABELS,
            'sync' => SyncRunner::last(self::KEY),
            'v6_count' => count($idx->v6()),
        ];
    }

    /** Rozwinięty zakres: jego adresy i wolne miejsce. */
    private function apiRangeTree(IpamIndex $idx, int $id): array
    {
        $m = $this->findRange($idx, $id);
        $dups = $idx->duplicates();
        $ips = $idx->addressesIn($m['start'], $m['end']);
        $free = [];
        foreach (IpCalc::freeIntervals($m['start'], $m['end'], array_map(fn ($ip) => [$ip, $ip], $ips)) as [$a, $b]) {
            $free[] = ['type' => 'hosts', 'n' => $a, 'first' => IpCalc::int2ip($a), 'last' => IpCalc::int2ip($b), 'count' => $b - $a + 1];
            if (count($free) >= self::MAX_FREE) {
                break;
            }
        }

        return [
            'node' => $this->rangeInfo($idx, $m),
            'children' => [],
            'addresses' => array_map(fn ($ip) => $this->addrInfo($idx, $ip, $dups), array_slice($ips, 0, self::MAX_ADDR)),
            'addresses_total' => count($ips),
            'free' => $free,
            'labels' => IpamIndex::LABELS,
            'sync' => SyncRunner::last(self::KEY),
            'v6_count' => count($idx->v6()),
        ];
    }

    /** Szczegóły zakresu do panelu po prawej. */
    private function apiRange(int $space, int $id): array
    {
        $idx = $this->index($space);
        $m = $this->findRange($idx, $id);
        $tree = $idx->tree();
        $dups = $idx->duplicates();
        [$container, $problem] = $idx->rangeContainer($m['start'], $m['end']);
        $ips = $idx->addressesIn($m['start'], $m['end']);

        return [
            'range' => $this->rangeInfo($idx, $m),
            'path' => $this->rangePath($tree, $id),
            'container' => $container ?? $tree->range($id)['node'],
            'problem' => $problem,
            'addresses' => array_map(fn ($ip) => $this->addrInfo($idx, $ip, $dups), array_slice($ips, 0, 200)),
            'addresses_total' => count($ips),
            'history' => $this->history(['r:' . $m['cidr'] . ':'], [$m['start'], $m['end']]),
            'roles' => IpamManual::RANGE_ROLES,
        ];
    }

    /** Najwięcej wolnych pozycji pokazywanych w jednym rozwinięciu drzewa. */
    private const MAX_FREE = 256;

    /**
     * Wolne miejsce w rozwiniętym węźle, do wplecenia między dzieci.
     *   - w agregacie i grupie: wyrównane prefiksy CIDR (maska zgodna z granicą bloku),
     *   - w sieci: przedziały wolnych adresów hostów.
     *
     * @return list<array>
     */
    private function treeFree(IpTree $tree, string $cidr): array
    {
        $n = $tree->node($cidr);
        $out = [];
        foreach ($tree->gaps($cidr) as [$a, $b]) {
            if ($n['subnet']) {
                $out[] = ['type' => 'hosts', 'n' => $a, 'first' => IpCalc::int2ip($a), 'last' => IpCalc::int2ip($b), 'count' => $b - $a + 1];
            } else {
                foreach (IpCalc::rangeToCidrs($a, $b) as $blk) {
                    $out[] = ['type' => 'prefix', 'n' => $blk['start'], 'cidr' => IpCalc::cidr($blk['start'], $blk['len']),
                        'len' => $blk['len'], 'size' => IpCalc::size($blk['len'])];
                }
            }
            if (count($out) >= self::MAX_FREE) {
                break;
            }
        }

        return array_slice($out, 0, self::MAX_FREE);
    }

    private function apiNode(int $space, string $cidr): array
    {
        $idx = $this->index($space);
        $tree = $idx->tree();
        $n = $tree->node($cidr);
        if ($n === null) {
            throw new ApiException("Nie ma węzła {$cidr}.", 404);
        }

        $free = $tree->freeBlocks($cidr, 30);
        $freeRanges = array_map(fn ($iv) => ['first' => IpCalc::int2ip($iv[0]), 'last' => IpCalc::int2ip($iv[1]), 'count' => $iv[1] - $iv[0] + 1],
            array_slice($n['free_intervals'], 0, 50));

        return [
            'node' => $this->nodeInfo($idx, $n),
            'path' => $tree->path($cidr),
            'free_blocks' => $free,
            'free_ranges' => $freeRanges,
            'free_ranges_total' => count($n['free_intervals']),
            'history' => $this->history(["n:{$cidr}:"], [$n['start'], $n['end']]),
            'roles' => IpamManual::PREFIX_ROLES,
        ];
    }

    /**
     * Historia: wpisy o podanych prefiksach kluczy i wpisy, których zakres
     * nachodzi na [od, do]. Wpis grupowy z listą adresów sprawdzamy dokładnie.
     */
    private function history(array $prefixes, array $overlaps): array
    {
        $rows = AuditLog::page(['module' => self::KEY, 'key_prefix' => $prefixes, 'overlaps' => $overlaps], 1, 150)['rows'];

        return array_values(array_filter($rows, function (array $r) use ($overlaps) {
            $list = $r['after']['ips'] ?? $r['before']['ips'] ?? null;
            if ($list === null || $r['range_from'] === null) {
                return true;
            }
            try {
                foreach (IpInput::parse((string) $list)['addresses'] ?? [] as $ip) {
                    if ($ip >= $overlaps[0] && $ip <= $overlaps[1]) {
                        return true;
                    }
                }

                return false;
            } catch (\InvalidArgumentException) {
                return true;
            }
        }));
    }

    private function apiSearch(int $space, string $q): array
    {
        if ($q === '') {
            throw new ApiException('Wpisz adres, prefiks albo tekst.');
        }
        $idx = $this->index($space);
        $tree = $idx->tree();
        $dups = $idx->duplicates();

        // Adres IPv4: najgłębszy węzeł i szczegóły adresu.
        $ip = IpCalc::ip2int($q);
        if ($ip !== null) {
            $node = $tree->deepest($ip);
            $range = $idx->rangeOf($ip);
            $path = $range ? $this->rangePath($tree, $range['id']) : ($node ? $tree->path($node) : []);

            return ['kind' => 'address', 'ip' => $q, 'path' => $path,
                'address' => $idx->address($ip) ? $this->addrInfo($idx, $ip, $dups) : null,
                'history' => $this->history(['a:' . $q . '/', 'a:' . $q . ':'], [$ip, $ip])];
        }

        // Prefiks: dokładny węzeł albo najgłębszy, który go zawiera.
        if (preg_match('#^\d{1,3}(\.\d{1,3}){3}/\d{1,2}$#', $q)) {
            try {
                $c = IpCalc::parseV4($q);
            } catch (\InvalidArgumentException $e) {
                throw new ApiException($e->getMessage());
            }
            $node = $tree->node($c['cidr']) ? $c['cidr'] : $tree->deepest($c['start']);

            return ['kind' => 'prefix', 'cidr' => $c['cidr'], 'exact' => $node === $c['cidr'], 'path' => $node ? $tree->path($node) : []];
        }

        // Tekst: opisy wpisów ręcznych, nazwy urządzeń i interfejsów, opisy portów.
        $needle = mb_strtolower($q);
        $hits = [];
        foreach ($idx->manualPrefixes() as $cidr => $m) {
            if (str_contains(mb_strtolower(implode(' ', [$m['name'], $m['description'], $m['owner'], $m['devices']])), $needle)) {
                $hits[] = ['type' => 'prefix', 'value' => $cidr, 'label' => $m['name'] ?: $m['description'], 'path' => $tree->path($cidr)];
            }
        }
        foreach ($idx->ranges() as $id => $m) {
            if (str_contains(mb_strtolower(implode(' ', [$m['cidr'], $m['role_label'], $m['name'], $m['description'], $m['owner'], $m['devices']])), $needle)) {
                $hits[] = ['type' => 'range', 'value' => $m['cidr'], 'id' => $id, 'first' => $m['first'],
                    'label' => $m['role_label'] . ' · ' . ($m['name'] ?: $m['description']), 'path' => $this->rangePath($tree, $id)];
            }
        }
        foreach ($idx->addresses() as $aip => $a) {
            $text = [];
            foreach ($a['rows'] as $r) {
                $text[] = $r['data']['device'] . ' ' . $r['data']['if'] . ' ' . $r['data']['alias'];
            }
            if ($a['manual']) {
                $text[] = implode(' ', [$a['manual']['name'], $a['manual']['description'], $a['manual']['owner'], $a['manual']['devices']]);
            }
            if (str_contains(mb_strtolower(implode(' ', $text)), $needle)) {
                $node = $tree->deepest($aip);
                $hits[] = ['type' => 'address', 'value' => IpCalc::int2ip($aip), 'label' => mb_substr(trim(implode(' · ', $text)), 0, 140),
                    'path' => $node ? $tree->path($node) : []];
            }
            if (count($hits) >= 100) {
                break;
            }
        }

        return ['kind' => 'text', 'hits' => $hits];
    }

    private function apiFree(int $space, string $within, int $len, int $count): array
    {
        if ($len < 8 || $len > 32) {
            throw new ApiException('Maska musi być z zakresu /8-/32.');
        }
        try {
            $w = IpCalc::parseV4($within, true);
        } catch (\InvalidArgumentException $e) {
            throw new ApiException($e->getMessage());
        }
        if ($len < $w['len']) {
            throw new ApiException("Blok /{$len} jest większy niż {$w['cidr']}.");
        }
        $idx = $this->index($space);

        // Szukamy tylko w adresacji, którą znamy (sieć, prefiks ręczny, adresacja wewnętrzna).
        if ($idx->tree()->realContainer($w['start'], $w['end']) === null) {
            throw new ApiException("{$w['cidr']} nie leży w żadnej znanej adresacji (sieć z LibreNMS, prefiks ręczny, adresacja wewnętrzna). "
                . 'Jeśli to Wasza pula, dodaj ją najpierw jako agregat.');
        }

        // Zajęte: prefiksy leżące wewnątrz (nie sam przeszukiwany i nie te, które go zawierają) i adresy.
        $busy = [];
        foreach (array_merge(array_keys($idx->networks()), array_keys($idx->manualPrefixes())) as $cidr) {
            $c = IpCalc::parseV4($cidr);
            if ($c['start'] >= $w['start'] && $c['end'] <= $w['end'] && $c['len'] > $w['len']) {
                $busy[] = [$c['start'], $c['end']];
            }
        }
        foreach (array_keys($idx->addresses()) as $ip) {
            if ($ip >= $w['start'] && $ip <= $w['end']) {
                $busy[] = [$ip, $ip];
            }
        }
        foreach ($idx->ranges() as $r) {
            if ($r['end'] >= $w['start'] && $r['start'] <= $w['end']) {
                $busy[] = [max($r['start'], $w['start']), min($r['end'], $w['end'])];
            }
        }
        // W sieci (podsieć interfejsu, sieć używana, pula) adres sieci i rozgłoszeniowy są zajęte.
        $isSubnet = $idx->knownNetwork($w['cidr'])
            || in_array($idx->manualPrefixes()[$w['cidr']]['role'] ?? '', ['used', 'pool'], true);
        if ($isSubnet && $w['len'] <= 30) {
            $busy[] = [$w['start'], $w['start']];
            $busy[] = [$w['end'], $w['end']];
        }

        $blocks = IpCalc::findFreeBlocks($w['start'], $w['end'], IpCalc::merge($busy), $len, max(1, min(50, $count)));

        return ['within' => $w['cidr'], 'len' => $len, 'blocks' => array_map(fn ($s) => IpCalc::cidr($s, $len), $blocks)];
    }

    private function apiIssues(int $space): array
    {
        $idx = $this->index($space);
        $tree = $idx->tree();

        $dups = [];
        foreach ($idx->duplicates() as $ip => $devices) {
            $dups[] = ['ip' => IpCalc::int2ip($ip), 'devices' => $devices];
        }

        $suspicious = array_map(fn ($r) => ['ip' => $r['data']['ip'], 'len' => $r['data']['len'], 'device' => $r['data']['device'],
            'if' => $r['data']['if']], $idx->suspicious());

        // Adresy z LibreNMS poza każdą prawdziwą siecią (np. aliasy /32).
        $orphans = [];
        foreach ($idx->addresses() as $ip => $a) {
            if ($a['rows'] === []) {
                continue;
            }
            if (! $tree->inNetwork($ip)) {
                $r = $a['rows'][0]['data'];
                $orphans[] = ['ip' => IpCalc::int2ip($ip), 'len' => $r['len'], 'device' => $r['device'], 'if' => $r['if']];
            }
        }

        // Rezerwacje, w których pojawiły się adresy z LibreNMS.
        $conflicts = [];
        foreach ($idx->manualPrefixes() as $cidr => $m) {
            if ($m['role'] === 'reserved' && ($inside = $idx->knownInside($m['start'], $m['end'])) !== []) {
                $conflicts[] = ['cidr' => $cidr, 'ips' => IpInput::compress(array_slice($inside, 0, 50)), 'count' => count($inside)];
            }
        }
        // Zakresy: rezerwacje z adresami z LibreNMS i zakresy, które przecięła sieć z LibreNMS.
        $rangeConflicts = [];
        foreach ($idx->ranges() as $id => $m) {
            if ($m['role'] === 'reserved' && ($inside = $idx->knownInside($m['start'], $m['end'])) !== []) {
                $conflicts[] = ['cidr' => $m['cidr'], 'range_id' => $id, 'first' => $m['first'],
                    'ips' => IpInput::compress(array_slice($inside, 0, 50)), 'count' => count($inside)];
            }
            if (($problem = $idx->rangeContainer($m['start'], $m['end'])[1]) !== null) {
                $rangeConflicts[] = ['id' => $id, 'label' => $m['cidr'], 'first' => $m['first'], 'problem' => $problem];
            }
        }

        return [
            'duplicates' => $dups,
            'suspicious' => $suspicious,
            'orphans' => array_slice($orphans, 0, 300),
            'orphans_total' => count($orphans),
            'reserved_conflicts' => $conflicts,
            'range_conflicts' => $rangeConflicts,
            // Wpisy ręczne po terminie albo z terminem w ciągu Expiry::WARN_DAYS dni.
            'expiry' => array_values(array_map(fn ($g) => array_intersect_key($g,
                array_flip(['id', 'kind', 'cidr', 'ip', 'first', 'label', 'count', 'role_label', 'name', 'description', 'owner', 'valid_until', 'expiry', 'days_left'])),
                array_filter($this->manual()->grouped($space), fn ($g) => $g['expiry'] !== null && empty($g['adopted_at'])))),
            'v6_count' => count($idx->v6()),
        ];
    }

    private function apiManualList(int $space): array
    {
        $idx = $this->index($space);
        $rows = array_map(function (array $g) use ($idx) {
            if ($g['kind'] === 'prefix') {
                $g['current'] = $idx->knownNetwork($g['cidr']) ? 'sieć jest w LibreNMS' : null;
            } elseif ($g['kind'] === 'range') {
                $g['current'] = $idx->rangeContainer($g['start'], $g['end'])[1] !== null ? 'konflikt z siecią (zobacz Uwagi)' : null;
            } else {
                $g['current'] = null;
            }

            return $g;
        }, $this->manual()->grouped($space));

        return ['rows' => $rows, 'prefix_roles' => IpamManual::PREFIX_ROLES, 'address_roles' => IpamManual::ADDRESS_ROLES,
            'range_roles' => IpamManual::RANGE_ROLES];
    }

    private function apiManualCreate(int $space, Request $request): array
    {
        $res = $this->manual()->create($space, (string) $request->input('target', ''),
            $request->only(self::FORM_FIELDS), self::comment($request), $this->index($space), $request->input('as') === 'range');

        return $res;
    }

    private function apiManualUpdate(Request $request): array
    {
        $entry = $this->manual()->find((int) $request->input('id', 0));
        if ($entry === null) {
            throw new ApiException('Nie ma takiego wpisu (mógł zostać usunięty).', 404);
        }

        return ['entry' => $this->manual()->update($entry['id'], $request->only(self::FORM_FIELDS),
            self::comment($request), $request->boolean('group'), $this->index($entry['space_id']))];
    }

    private function apiManualToRange(Request $request): array
    {
        $entry = $this->manual()->find((int) $request->input('id', 0));
        if ($entry === null) {
            throw new ApiException('Nie ma takiego wpisu (mógł zostać usunięty).', 404);
        }

        return ['entry' => $this->manual()->toRange($entry['id'], self::comment($request), $this->index($entry['space_id']))];
    }

    private function apiManualDelete(Request $request): array
    {
        return ['deleted' => $this->manual()->delete((int) $request->input('id', 0), self::comment($request), $request->boolean('group'))];
    }

    // ---------------------------------------------------------------- import z RIPE

    /** Pobieranie JSON-a przez klienta HTTP LibreNMS (proxy z ustawień LibreNMS). */
    private static function ripeFetch(): \Closure
    {
        return function (string $url): ?array {
            try {
                $client = class_exists(\LibreNMS\Util\Http::class) ? \LibreNMS\Util\Http::client() : \Illuminate\Support\Facades\Http::withOptions([]);
                $res = $client->timeout(10)->acceptJson()->get($url);
            } catch (\Throwable) {
                return null;
            }
            if ($res->status() === 404) {
                return []; // baza RIPE: brak wyników
            }

            return $res->successful() && is_array($res->json()) ? $res->json() : null;
        };
    }

    /**
     * Prefiksy AS z RIPE z oceną względem rejestru: czy już jest wpis, czy leży
     * w agregacie albo w szerszym kandydacie, ile sieci LibreNMS obejmuje.
     * "checked" = proponowane do dodania.
     */
    private function apiRipeLookup(int $space, string $asn): array
    {
        try {
            $res = (new RipeLookup(self::ripeFetch()))->lookup(RipeLookup::parseAsn($asn));
        } catch (\InvalidArgumentException $e) {
            throw new ApiException($e->getMessage());
        } catch (\RuntimeException $e) {
            throw new ApiException($e->getMessage(), 502);
        }

        $idx = $this->index($space);
        $manual = $idx->manualPrefixes();
        $nets = [];
        foreach (array_keys($idx->networks()) as $c) {
            $p = IpCalc::parseV4((string) $c);
            $nets[] = [$p['start'], $p['end']];
        }
        $v4 = [];
        foreach ($res['prefixes'] as $p) {
            if ($p['family'] === 4) {
                $v4[$p['cidr']] = IpCalc::parseV4($p['cidr']);
            }
        }

        foreach ($res['prefixes'] as &$p) {
            $p['sources_label'] = array_map(fn ($s) => RipeLookup::SOURCES[$s] ?? $s, $p['sources']);
            if ($p['family'] !== 4) {
                $p += ['unsupported' => true, 'checked' => false, 'exists' => null, 'in_aggregate' => null, 'parent' => null, 'lnms' => 0];
                continue;
            }
            $r = $v4[$p['cidr']];
            $p['unsupported'] = false;
            $p['exists'] = isset($manual[$p['cidr']]) ? (IpamManual::PREFIX_ROLES[$manual[$p['cidr']]['role']] ?? $manual[$p['cidr']]['role']) : null;
            $p['in_aggregate'] = null;
            foreach ($manual as $c => $m) {
                if ($c !== $p['cidr'] && $m['role'] === 'aggregate' && $m['start'] <= $r['start'] && $m['end'] >= $r['end']) {
                    $p['in_aggregate'] = $c;
                    break;
                }
            }
            $p['parent'] = null;
            foreach ($v4 as $c => $o) {
                if ($c !== $p['cidr'] && $o['start'] <= $r['start'] && $o['end'] >= $r['end']) {
                    $p['parent'] = $c;
                    break;
                }
            }
            $p['lnms'] = count(array_filter($nets, fn ($n) => $n[0] >= $r['start'] && $n[1] <= $r['end']));
            // Nazwa agregatu: netname przydziału, jeśli to dokładnie ten prefiks, inaczej opis z route.
            $p['name'] = ($p['inetnum']['range'] ?? null) === $p['cidr'] ? ($p['inetnum']['netname'] ?? null) : null;
            $p['name'] ??= $p['route_descr'];
            $p['checked'] = $p['exists'] === null && $p['in_aggregate'] === null && $p['parent'] === null;
        }
        unset($p);

        return $res;
    }

    /** Dodaje wybrane prefiksy jako agregaty; każdy osobno, błąd jednego nie zatrzymuje reszty. */
    private function apiRipeImport(int $space, Request $request): array
    {
        try {
            $asn = RipeLookup::parseAsn((string) $request->input('asn', ''));
        } catch (\InvalidArgumentException $e) {
            throw new ApiException($e->getMessage());
        }
        $items = $request->input('items', []);
        if (! is_array($items) || ! $items) {
            throw new ApiException('Zaznacz co najmniej jeden prefiks.');
        }
        if (count($items) > 200) {
            throw new ApiException('Najwięcej 200 prefiksów naraz.');
        }
        $desc = trim((string) $request->input('description', '')) ?: "Przydział AS{$asn} (z RIPE)";
        $comment = self::comment($request) ?? "dodano z RIPE dla AS{$asn}";

        $out = [];
        foreach ($items as $it) {
            $cidr = trim((string) (is_array($it) ? ($it['cidr'] ?? '') : $it));
            $name = is_array($it) ? mb_substr(trim((string) ($it['name'] ?? '')), 0, 64) : '';
            try {
                if ($cidr === '' || str_contains($cidr, ':')) {
                    throw new ApiException('IPv6 nie jest jeszcze obsługiwane.');
                }
                $this->manual()->create($space, $cidr, ['role' => 'aggregate', 'name' => $name, 'description' => $desc],
                    $comment, $this->index($space));
                $out[] = ['cidr' => $cidr, 'ok' => true];
            } catch (ApiException $e) {
                $out[] = ['cidr' => $cidr, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        return ['results' => $out, 'added' => count(array_filter($out, fn ($r) => $r['ok']))];
    }
}
