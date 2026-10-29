<?php

/*
 * Ipam6Module.php - moduł adresacji IPv6 (osobna zakładka).
 *
 * Adresy IPv6 z LibreNMS zbiera moduł adresacji IPv4 (IpamCollector, migawka
 * "ipam/address", family 6), więc ten moduł nie ma własnej synchronizacji -
 * czyta tę migawkę. Wpisy ręczne (prefiksy, adresy, zakresy) są w osobnej tabeli
 * (Ip6Manual), przestrzenie adresowe są wspólne z IPv4 (IpamSpaces).
 *
 * Zasady widoku jak w IPv4: domyślnie w drzewie jest adresacja wewnętrzna
 * (ustawienie "ipam6_private", domyślnie ULA fc00::/7), w której coś leży, oraz
 * wpisy ręczne/RIPE; reszta LibreNMS (np. połączenia z peerami) za przełącznikiem
 * outside=1. Adresy wyłączonych hostów za przełącznikiem stale=1. Blokady przy
 * zapisie i "Wolne /N" zawsze liczą z pełnych danych (Ip6Context).
 *
 * Wymaga rozszerzenia PHP gmp - bez niego API zwraca czytelny błąd, a reszta
 * wtyczki działa.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Ipam6;

use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Ispnetella\NetellaReg\Core\Access;
use Ispnetella\NetellaReg\Core\ApiException;
use Ispnetella\NetellaReg\Core\AuditLog;
use Ispnetella\NetellaReg\Core\Db;
use Ispnetella\NetellaReg\Core\Lang;
use Ispnetella\NetellaReg\Core\Settings;
use Ispnetella\NetellaReg\Core\Snapshot;
use Ispnetella\NetellaReg\Core\SyncContext;
use Ispnetella\NetellaReg\Core\SyncRunner;
use Ispnetella\NetellaReg\Modules\AbstractModule;
use Ispnetella\NetellaReg\Modules\Ipam\IpamModule;
use Ispnetella\NetellaReg\Modules\Ipam\IpamSpaces;
use Ispnetella\NetellaReg\Modules\Ipam\RipeLookup;

class Ipam6Module extends AbstractModule
{
    public const KEY = 'ipam6';

    /** Maski krótsze niż ta z LibreNMS traktujemy jak błąd danych (np. /0). */
    private const MIN_LNMS_LEN = 16;

    private const MAX_ROWS = 300;

    private const VIEW_ACTIONS = ['tree', 'node', 'range', 'search', 'manual_list'];

    private const FORM_FIELDS = ['role', 'name', 'description', 'owner', 'vlan', 'devices', 'valid_until'];

    /** Stany adresu: LibreNMS, wyłączony host, wpis ręczny, rezerwacja. */
    private const RANK = ['r' => 1, 'm' => 2, 's' => 3, 'l' => 4];

    private bool $showStale = true;

    private bool $showOutside = true;

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return Lang::t('Adresy IPv6');
    }

    public function schemaVersion(): int
    {
        return 2;
    }

    public function migrate(Connection $db, int $from): void
    {
        if ($from < 1) {
            IpamSpaces::migrate($db);
            Ip6Manual::migrate($db);
        }
        if ($from < 2) {
            Ip6Manual::migrateWiderCidr($db);
        }
    }

    /** Dane z LibreNMS zbiera moduł IPv4 - tu tylko liczba wpisów do logu. */
    public function sync(SyncContext $ctx): array
    {
        $n = $ctx->db->table(Ip6Manual::TABLE)->whereNull('deleted_at')->count();
        $ctx->log(Settings::moduleEnabled(IpamModule::KEY) ? Lang::t('{n} wpisów ręcznych IPv6', ['n' => $n])
            : Lang::t('{n} wpisów ręcznych IPv6; moduł „Adresy IPv4” wyłączony - adresy IPv6 z LibreNMS się nie odświeżają', ['n' => $n]));

        return ['manual' => $n];
    }

    public function tabView(): ?string
    {
        return 'modules.ipam6.tab';
    }

    public function tabData(): array
    {
        return ['gmp' => Ip6Calc::available(), 'ipamEnabled' => Settings::moduleEnabled(IpamModule::KEY)];
    }

    public function apiPermission(string $action, string $method): string
    {
        return match ($action) {
            'manual_delete' => Access::DELETE,
            'manual_create', 'manual_update', 'ripe_lookup', 'ripe_import' => Access::EDIT,
            default => Access::VIEW,
        };
    }

    public function api(string $action, Request $request): array
    {
        if (! Ip6Calc::available()) {
            throw new ApiException(Lang::t('Brak rozszerzenia PHP gmp - zainstaluj php-gmp, żeby używać adresacji IPv6.'), 501);
        }
        $space = max(1, (int) $request->input('space', IpamSpaces::GLOBAL_ID));
        $view = in_array($action, self::VIEW_ACTIONS, true);
        $this->showStale = ! $view || (string) $request->input('stale', '1') !== '0';
        $this->showOutside = ! $view || (string) $request->input('outside', '1') !== '0';

        return match ($action) {
            'spaces' => ['spaces' => array_values((new IpamSpaces(Db::connection()))->all())],
            'tree' => $this->apiTree($space, trim((string) $request->query('node', ''))),
            'node' => $this->apiNode($space, trim((string) $request->query('cidr', ''))),
            'range' => $this->apiRange($space, (int) $request->query('id', 0)),
            'search' => $this->apiSearch($space, trim((string) $request->query('q', ''))),
            'free' => $this->apiFree($space, trim((string) $request->query('within', '')), (int) $request->query('len', 64),
                (int) $request->query('count', 10)),
            'manual_list' => $this->apiManualList($space),
            'manual_create' => $this->post($request) ?? $this->manual()->create($space, (string) $request->input('target', ''),
                $request->only(self::FORM_FIELDS), self::comment($request), $this->context($space)),
            'manual_update' => $this->post($request) ?? $this->apiManualUpdate($request),
            'manual_delete' => $this->post($request) ?? ['deleted' => $this->manual()->delete((int) $request->input('id', 0), self::comment($request))],
            'ripe_lookup' => $this->apiRipeLookup($space, trim((string) $request->query('asn', ''))),
            'ripe_import' => $this->post($request) ?? $this->apiRipeImport($space, $request),
            default => parent::api($action, $request),
        };
    }

    // ---------------------------------------------------------------- dane

    private function post(Request $request): ?array
    {
        if (! $request->isMethod('POST')) {
            throw new ApiException(Lang::t('Wymagany POST.'), 405);
        }

        return null;
    }

    private static function comment(Request $request): ?string
    {
        $c = trim((string) $request->input('comment', ''));

        return $c === '' ? null : mb_substr($c, 0, 1000);
    }

    private function manual(): Ip6Manual
    {
        return new Ip6Manual(Db::connection());
    }

    /** @return list<string> adresacja wewnętrzna IPv6 z ustawienia */
    private static function implicitPrefixes(): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', (string) Settings::get('ipam6_private', 'fc00::/7'), -1, PREG_SPLIT_NO_EMPTY) as $p) {
            try {
                $c = Ip6Calc::parse($p, true);
                if ($c['len'] < 128) {
                    $out[$c['cidr']] = $c['cidr'];
                }
            } catch (\InvalidArgumentException) {
            }
        }

        return array_values($out);
    }

    /** @return list<int> poziomy grupowania */
    private static function levels(): array
    {
        $out = array_filter(array_map('intval', preg_split('/[\s,;]+/', (string) Settings::get('ipam6_levels', '32,48,56,64'))),
            fn ($l) => $l >= 8 && $l <= 124);
        sort($out);

        return array_values(array_unique($out)) ?: [32, 48, 56, 64];
    }

    /** @return array{0:array,1:array} adresy (hex => wpis) i sieci (cidr => wiersze) z LibreNMS */
    private function lnms(int $space, array $spaces): array
    {
        $mode = Settings::disabledMode();
        $rows = (new Snapshot(Db::connection()))->rows(IpamModule::KEY, IpamModule::TYPE, fn ($q) => $q->whereNull('gone_at'));
        $addr = $networks = [];
        $suspicious = 0;
        foreach ($rows as $row) {
            $d = $row['data'];
            if ((int) ($d['family'] ?? 4) !== 6 || IpamSpaces::resolve($spaces, $d['ctx'] ?? null) !== $space) {
                continue;
            }
            $disabled = ! empty($d['disabled']);
            if ($disabled && $mode === 'ignore') {
                continue;
            }
            $ip = Ip6Calc::ip2gmp((string) $d['ip']);
            $len = (int) $d['len'];
            if ($ip === null) {
                continue;
            }
            if ($len < self::MIN_LNMS_LEN) {
                $suspicious++;
                continue;
            }
            $state = $disabled ? 's' : 'l';
            $hex = Ip6Calc::hex($ip);
            $addr[$hex] ??= ['ip' => $ip, 'rows' => [], 'state' => 's', 'manual' => null];
            $addr[$hex]['rows'][] = $d + ['state' => $state];
            if ($state === 'l') {
                $addr[$hex]['state'] = 'l';
            }
            if ($len < 128) {
                $networks[Ip6Calc::cidr(Ip6Calc::network($ip, $len), $len)][] = $d + ['state' => $state];
            }
        }

        return [$addr, $networks, $suspicious];
    }

    /**
     * Wszystko o przestrzeni: adresy i sieci z LibreNMS, wpisy ręczne, drzewo widoku.
     */
    private function data(int $space): array
    {
        $spaces = (new IpamSpaces(Db::connection()))->all();
        if (! isset($spaces[$space])) {
            throw new ApiException(Lang::t('Nie ma takiej przestrzeni adresowej.'), 404);
        }
        [$lnmsAddr, $networks, $suspicious] = $this->lnms($space, $spaces);

        $manual = $mAddr = $ranges = [];
        foreach ($this->manual()->active($space) as $m) {
            $m['start'] = Ip6Calc::fromHex($m['start_hex']);
            $m['end'] = Ip6Calc::fromHex($m['end_hex']);
            match ($m['kind']) {
                'address' => $mAddr[$m['start_hex']] = $m,
                'range' => $ranges[$m['id']] = $m,
                default => $manual[$m['cidr']] = $m,
            };
        }
        $implicit = array_map(fn ($c) => Ip6Calc::parse($c), self::implicitPrefixes());

        // Wszystkie adresy: LibreNMS + wpisy ręczne (stan o najwyższej randze).
        $allAddr = $lnmsAddr;
        foreach ($mAddr as $hex => $m) {
            $st = $m['role'] === 'reserved' ? 'r' : 'm';
            $allAddr[$hex] ??= ['ip' => $m['start'], 'rows' => [], 'state' => $st, 'manual' => null];
            $allAddr[$hex]['manual'] = $m;
            if (self::RANK[$st] > self::RANK[$allAddr[$hex]['state']]) {
                $allAddr[$hex]['state'] = $st;
            }
        }

        $real = $manual;
        foreach (array_keys($networks) as $c) {
            $real[$c] ??= Ip6Calc::parse($c);
        }
        foreach ($implicit as $p) {
            $real[$p['cidr']] ??= $p;
        }
        $ctx = new Ip6Context($lnmsAddr, array_keys($networks), $real);

        $covered = function (\GMP $s, \GMP $e) use ($manual, $implicit): bool {
            foreach (array_merge(array_values($manual), $implicit) as $p) {
                if (Ip6Calc::within($p['start'], $p['end'], $s, $e)) {
                    return true;
                }
            }

            return false;
        };

        // Widok: bez wyłączonych hostów (stale=0) i bez adresacji spoza agregatów (outside=0).
        // Wpisy ręczne są zawsze widoczne.
        $hidden = 0;
        $view = [];
        foreach ($allAddr as $hex => $a) {
            if ($a['manual'] === null) {
                if (! $this->showStale && $a['state'] === 's') {
                    continue;
                }
                if (! $this->showOutside && ! $covered($a['ip'], $a['ip'])) {
                    $hidden++;
                    continue;
                }
            }
            $view[$hex] = $a;
        }
        $viewNets = [];
        foreach ($networks as $cidr => $nrows) {
            $active = $this->showStale ? $nrows : array_filter($nrows, fn ($r) => $r['state'] === 'l');
            $c = $real[$cidr];
            if ($active === [] || (! $this->showOutside && ! $covered($c['start'], $c['end']))) {
                continue;
            }
            $viewNets[$cidr] = array_values($active);
        }

        $prefixes = [];
        foreach ($viewNets as $cidr => $nrows) {
            $c = $real[$cidr];
            // Rola wpisu ręcznego na tym samym prefiksie wygrywa (agregat = blok, nie sieć).
            $sub = isset($manual[$cidr]) ? $manual[$cidr]['role'] === 'used' : true;
            $prefixes[] = ['start' => $c['start'], 'len' => $c['len'], 'src' => 'lnms', 'subnet' => $sub];
        }
        foreach ($manual as $m) {
            $prefixes[] = ['start' => $m['start'], 'len' => $m['len'], 'src' => 'manual', 'subnet' => $m['role'] === 'used'];
        }
        foreach ($implicit as $p) {
            $used = false;
            $things = array_merge(array_map(fn ($a) => [$a['ip'], $a['ip']], $view), array_map(fn ($m) => [$m['start'], $m['end']], array_merge($manual, $ranges)));
            foreach ($things as [$s, $e]) {
                if (Ip6Calc::within($p['start'], $p['end'], $s, $e)) {
                    $used = true;
                    break;
                }
            }
            if ($used) {
                $prefixes[] = ['start' => $p['start'], 'len' => $p['len'], 'src' => 'implicit', 'meta' => ['label' => Lang::t('adresacja wewnętrzna (ULA)')]];
            }
        }
        $tree = new Ip6Tree($prefixes, array_map(fn ($a) => $a['ip'], array_values($view)), self::levels(),
            array_map(fn ($r) => ['id' => $r['id'], 'start' => $r['start'], 'end' => $r['end']], array_values($ranges)));

        return ['tree' => $tree, 'addr' => $view, 'all_addr' => $allAddr, 'networks' => $viewNets, 'all_networks' => $networks,
            'manual' => $manual, 'ranges' => $ranges, 'real' => $real, 'ctx' => $ctx, 'hidden' => $hidden, 'suspicious' => $suspicious];
    }

    /** Kontekst blokad z pełnych danych (bez filtrów widoku). */
    private function context(int $space): Ip6Context
    {
        $saved = [$this->showStale, $this->showOutside];
        [$this->showStale, $this->showOutside] = [true, true];
        try {
            return $this->data($space)['ctx'];
        } finally {
            [$this->showStale, $this->showOutside] = $saved;
        }
    }

    private static function lnmsRow(array $r): array
    {
        return ['device' => $r['device'], 'device_id' => $r['device_id'], 'if' => $r['if'], 'alias' => $r['alias'] ?? null,
            'ip' => $r['ip'], 'len' => $r['len'], 'vlan' => $r['vlan'] ?? null, 'state' => $r['state']];
    }

    private static function publicManual(?array $m): ?array
    {
        if ($m === null) {
            return null;
        }
        unset($m['start'], $m['end']);

        return $m;
    }

    private function nodeInfo(array $data, array $n): array
    {
        $lnms = array_map([self::class, 'lnmsRow'], $data['networks'][$n['cidr']] ?? []);
        $manual = $data['manual'][$n['cidr']] ?? null;
        $label = $manual ? ($manual['name'] ?: $manual['description']) : null;
        if ($label === null && $lnms !== []) {
            $label = implode(', ', array_slice(array_values(array_unique(array_filter(array_map(fn ($l) => $l['alias'] ?: $l['device'] . ' ' . $l['if'], $lnms)))), 0, 3));
        }
        $label ??= $n['meta']['label'] ?? null;

        return [
            'cidr' => $n['cidr'], 'n' => $n['start_hex'], 'len' => $n['len'],
            'first' => Ip6Calc::gmp2ip($n['start']), 'last' => Ip6Calc::gmp2ip($n['end']),
            'virtual' => Ip6Tree::isVirtual($n), 'outside' => Ip6Tree::isOutside($n),
            'sources' => array_keys($n['sources']), 'subnet' => $n['subnet'],
            'state' => $lnms === [] ? null : (array_filter($lnms, fn ($l) => $l['state'] === 'l') ? 'l' : 's'),
            'stats' => $n['stats'], 'children' => count($n['children']), 'label' => $label,
            'lnms' => $lnms, 'manual' => self::publicManual($manual),
        ];
    }

    private function addrInfo(array $data, string $hex): array
    {
        $a = $data['all_addr'][$hex];
        $r = self::rangeOf($data, $a['ip']);

        return ['ip' => Ip6Calc::gmp2ip($a['ip']), 'n' => $hex, 'state' => $a['state'], 'rows' => array_map([self::class, 'lnmsRow'], $a['rows']),
            'manual' => self::publicManual($a['manual']), 'range' => self::rangeRef($r),
            // Adres z LibreNMS w zakresie ręcznym: możliwy błąd konfiguracji sieci.
            'conflict' => $r !== null && $a['rows'] !== []];
    }

    /** Zakres ręczny, w którym leży adres, albo null. */
    private static function rangeOf(array $data, \GMP $ip): ?array
    {
        foreach ($data['ranges'] as $r) {
            if (gmp_cmp($ip, $r['start']) >= 0 && gmp_cmp($ip, $r['end']) <= 0) {
                return $r;
            }
        }

        return null;
    }

    private static function rangeRef(?array $r): ?array
    {
        return $r === null ? null : ['id' => $r['id'], 'label' => $r['cidr'], 'role_label' => $r['role_label']];
    }

    /** Adresy (LibreNMS i ręczne) w przedziale - hex, posortowane. */
    private static function addressesIn(array $data, \GMP $s, \GMP $e): array
    {
        $out = array_keys(array_filter($data['all_addr'], fn ($a) => gmp_cmp($a['ip'], $s) >= 0 && gmp_cmp($a['ip'], $e) <= 0));
        sort($out);

        return $out;
    }

    private function rangeInfo(array $data, array $r): array
    {
        $inside = self::addressesIn($data, $r['start'], $r['end']);
        // Znaczek liczy tylko aktywne hosty; wyłączone widać po włączeniu przełącznika.
        $lnms = count(array_filter($inside, fn ($hex) => $data['all_addr'][$hex]['state'] === 'l'));

        return ['range' => true, 'id' => $r['id'], 'cidr' => $r['cidr'], 'n' => $r['start_hex'], 'len' => 128,
            'first' => $r['first'], 'last' => $r['last'], 'size' => $r['size'], 'addresses' => count($inside), 'conflicts' => $lnms,
            'label' => $r['name'] ?: $r['description'], 'manual' => self::publicManual($r)];
    }

    // ---------------------------------------------------------------- API

    private function apiTree(int $space, string $node): array
    {
        $data = $this->data($space);
        $tree = $data['tree'];
        $free = $rangeRows = [];
        if ($node === '') {
            $children = $tree->roots();
            $addrIdx = $tree->rootAddresses();
        } else {
            $n = $tree->node($node) ?? throw new ApiException(Lang::t('Nie ma węzła {cidr}.', ['cidr' => $node]), 404);
            $children = $n['children'];
            $addrIdx = $n['addr'];
            foreach ($n['ranges'] as $id) {
                $rangeRows[] = $this->rangeInfo($data, $data['ranges'][$id]);
            }
            foreach ($tree->gaps($node) as [$a, $b]) {
                foreach (Ip6Calc::rangeToCidrs($a, $b, 64) as $blk) {
                    $free[] = ['n' => Ip6Calc::hex($blk['start']), 'cidr' => Ip6Calc::cidr($blk['start'], $blk['len']), 'len' => $blk['len']];
                }
                if (count($free) >= 128) {
                    break;
                }
            }
            $free = array_slice($free, 0, 128);
        }
        $addresses = [];
        foreach (array_slice($addrIdx, 0, self::MAX_ROWS) as $i) {
            $addresses[] = $this->addrInfo($data, $tree->address($i)['hex']);
        }

        return [
            'children' => array_merge(array_map(fn ($c) => $this->nodeInfo($data, $tree->node($c)), $children), $rangeRows),
            'addresses' => $addresses,
            'addresses_total' => count($addrIdx),
            'free' => $free,
            'hidden' => $data['hidden'],
            'suspicious' => $data['suspicious'],
            'sync' => SyncRunner::last(IpamModule::KEY),
        ];
    }

    private function apiNode(int $space, string $cidr): array
    {
        $data = $this->data($space);
        $n = $data['tree']->node($cidr) ?? throw new ApiException(Lang::t('Nie ma węzła {cidr}.', ['cidr' => $cidr]), 404);

        return [
            'node' => $this->nodeInfo($data, $n),
            'path' => $data['tree']->path($cidr),
            'free_blocks' => $data['tree']->freeBlocks($cidr, 30),
            'history' => AuditLog::page(['module' => self::KEY, 'key_prefix' => ["n:{$cidr}:"]], 1, 100)['rows'],
        ];
    }

    private function apiRange(int $space, int $id): array
    {
        $data = $this->data($space);
        $r = $data['ranges'][$id] ?? throw new ApiException(Lang::t('Nie ma takiego zakresu (mógł zostać usunięty).'), 404);
        $node = $data['tree']->range($id)['node'] ?? null;
        $inside = self::addressesIn($data, $r['start'], $r['end']);

        return [
            'range' => $this->rangeInfo($data, $r),
            'path' => $node ? $data['tree']->path($node) : [],
            'container' => $data['ctx']->rangeContainer($r['start'], $r['end'])[0],
            'addresses' => array_map(fn ($hex) => $this->addrInfo($data, $hex), array_slice($inside, 0, 200)),
            'addresses_total' => count($inside),
            'history' => AuditLog::page(['module' => self::KEY, 'key_prefix' => ["r:{$r['cidr']}:"]], 1, 100)['rows'],
        ];
    }

    private function apiSearch(int $space, string $q): array
    {
        if ($q === '') {
            throw new ApiException(Lang::t('Wpisz adres, prefiks albo tekst.'));
        }
        $data = $this->data($space);
        $tree = $data['tree'];
        $p = null;
        try {
            $p = Ip6Calc::parse($q);
        } catch (\InvalidArgumentException) {
        }

        if ($p !== null && $p['len'] === 128) {
            $hex = $p['start_hex'];
            $node = $tree->deepest($p['start']);
            $known = isset($data['all_addr'][$hex]);

            return ['kind' => 'address', 'ip' => Ip6Calc::gmp2ip($p['start']), 'path' => $node ? $tree->path($node) : [],
                'address' => $known ? $this->addrInfo($data, $hex) : null, 'hidden' => $known && ! isset($data['addr'][$hex]),
                'range' => self::rangeRef(self::rangeOf($data, $p['start'])),
                'history' => AuditLog::page(['module' => self::KEY, 'key_prefix' => ['a:' . Ip6Calc::gmp2ip($p['start']) . ':']], 1, 50)['rows']];
        }
        if ($p !== null) {
            $node = $tree->node($p['cidr']) ? $p['cidr'] : $tree->deepest($p['start']);

            return ['kind' => 'prefix', 'cidr' => $p['cidr'], 'exact' => $node === $p['cidr'], 'path' => $node ? $tree->path($node) : []];
        }

        // Tekst: wpisy ręczne i nazwy urządzeń, interfejsów, opisy portów.
        $needle = mb_strtolower($q);
        $text = fn (array $m) => mb_strtolower(implode(' ', [$m['cidr'], $m['role_label'], $m['name'], $m['description'], $m['owner'], $m['devices']]));
        $hits = [];
        foreach ($data['manual'] as $cidr => $m) {
            if (str_contains($text($m), $needle)) {
                $hits[] = ['type' => 'prefix', 'value' => $cidr, 'label' => $m['name'] ?: $m['description'], 'path' => $tree->node($cidr) ? $tree->path($cidr) : []];
            }
        }
        foreach ($data['ranges'] as $id => $m) {
            if (str_contains($text($m), $needle)) {
                $node = $tree->range($id)['node'] ?? null;
                $hits[] = ['type' => 'range', 'id' => $id, 'value' => $m['cidr'], 'label' => $m['role_label'] . ' · ' . ($m['name'] ?: $m['description']),
                    'path' => $node ? $tree->path($node) : []];
            }
        }
        foreach ($data['all_addr'] as $hex => $a) {
            $parts = array_map(fn ($r) => $r['device'] . ' ' . $r['if'] . ' ' . ($r['alias'] ?? ''), $a['rows']);
            if ($a['manual']) {
                $parts[] = $text($a['manual']);
            }
            if (str_contains(mb_strtolower(implode(' ', $parts)), $needle)) {
                $node = $tree->deepest($a['ip']);
                $hits[] = ['type' => 'address', 'value' => Ip6Calc::gmp2ip($a['ip']), 'label' => mb_substr(trim(implode(' · ', $parts)), 0, 140),
                    'path' => $node ? $tree->path($node) : [], 'hidden' => ! isset($data['addr'][$hex])];
            }
            if (count($hits) >= 100) {
                break;
            }
        }

        return ['kind' => 'text', 'hits' => $hits];
    }

    /** Pierwsze wolne bloki /len w prefiksie; zajęte liczone z pełnych danych. */
    private function apiFree(int $space, string $within, int $len, int $count): array
    {
        try {
            $w = Ip6Calc::parse($within, true);
        } catch (\InvalidArgumentException $e) {
            throw new ApiException($e->getMessage());
        }
        if ($len < $w['len'] || $len > 128) {
            throw new ApiException(Lang::t('Maska musi być z zakresu /{len}-/128.', ['len' => $w['len']]));
        }
        $saved = [$this->showStale, $this->showOutside];
        [$this->showStale, $this->showOutside] = [true, true];
        try {
            $data = $this->data($space);
        } finally {
            [$this->showStale, $this->showOutside] = $saved;
        }
        $busy = [];
        foreach ($data['real'] as $p) {
            // Prefiksy leżące wewnątrz (nie sam przeszukiwany i nie te, które go zawierają).
            if ($p['len'] > $w['len'] && Ip6Calc::within($w['start'], $w['end'], $p['start'], $p['end'])) {
                $busy[] = [$p['start'], $p['end']];
            }
        }
        foreach ($data['ranges'] as $r) {
            $busy[] = [$r['start'], $r['end']];
        }
        foreach ($data['all_addr'] as $a) {
            $busy[] = [$a['ip'], $a['ip']];
        }
        $blocks = Ip6Calc::findFreeBlocks($w['start'], $w['end'], $busy, $len, max(1, min(50, $count)));

        return ['within' => $w['cidr'], 'len' => $len, 'blocks' => array_map(fn ($s) => Ip6Calc::cidr($s, $len), $blocks)];
    }

    private function apiManualList(int $space): array
    {
        $data = $this->data($space);
        $rows = array_merge(array_values($data['manual']), array_values($data['ranges']),
            array_values(array_filter(array_map(fn ($a) => $a['manual'], $data['all_addr']))));
        usort($rows, fn ($a, $b) => [$a['start_hex'], $a['len']] <=> [$b['start_hex'], $b['len']]);

        return ['rows' => array_map([self::class, 'publicManual'], $rows), 'prefix_roles' => array_map(fn ($l) => Lang::t($l), Ip6Manual::PREFIX_ROLES),
            'address_roles' => array_map(fn ($l) => Lang::t($l), Ip6Manual::ADDRESS_ROLES), 'range_roles' => array_map(fn ($l) => Lang::t($l), Ip6Manual::RANGE_ROLES)];
    }

    private function apiManualUpdate(Request $request): array
    {
        $entry = $this->manual()->find((int) $request->input('id', 0)) ?? throw new ApiException(Lang::t('Nie ma takiego wpisu (mógł zostać usunięty).'), 404);

        return ['entry' => $this->manual()->update($entry['id'], $request->only(self::FORM_FIELDS), self::comment($request), $this->context($entry['space_id']))];
    }

    /** Prefiksy IPv6 numeru AS z RIPE z oceną względem rejestru IPv6 (jak w IPv4). */
    private function apiRipeLookup(int $space, string $asn): array
    {
        try {
            $res = (new RipeLookup(IpamModule::ripeFetch()))->lookup(RipeLookup::parseAsn($asn));
        } catch (\InvalidArgumentException $e) {
            throw new ApiException($e->getMessage());
        } catch (\RuntimeException $e) {
            throw new ApiException($e->getMessage(), 502);
        }
        $saved = [$this->showStale, $this->showOutside];
        [$this->showStale, $this->showOutside] = [true, true];
        try {
            $data = $this->data($space);
        } finally {
            [$this->showStale, $this->showOutside] = $saved;
        }

        $v6 = [];
        foreach ($res['prefixes'] as $p) {
            if ($p['family'] === 6) {
                $v6[$p['cidr']] = Ip6Calc::parse($p['cidr']);
            }
        }
        foreach ($res['prefixes'] as &$p) {
            $p['sources_label'] = RipeLookup::sourceLabels($p['sources']);
            if ($p['family'] !== 6) {
                $p += ['unsupported' => true, 'unsupported_label' => Lang::t('IPv4 - dodaj w zakładce „Adresy IPv4”'), 'checked' => false,
                    'exists' => null, 'in_aggregate' => null, 'parent' => null, 'lnms' => 0, 'name' => null];
                continue;
            }
            $r = $v6[$p['cidr']];
            $p['unsupported'] = false;
            $p['exists'] = isset($data['manual'][$p['cidr']]) ? $data['manual'][$p['cidr']]['role_label'] : null;
            $p['in_aggregate'] = null;
            foreach ($data['manual'] as $c => $m) {
                if ($c !== $p['cidr'] && $m['role'] === 'aggregate' && Ip6Calc::within($m['start'], $m['end'], $r['start'], $r['end'])) {
                    $p['in_aggregate'] = $c;
                    break;
                }
            }
            $p['parent'] = null;
            foreach ($v6 as $c => $o) {
                if ($c !== $p['cidr'] && Ip6Calc::within($o['start'], $o['end'], $r['start'], $r['end'])) {
                    $p['parent'] = $c;
                    break;
                }
            }
            $p['lnms'] = count(array_filter(array_keys($data['all_networks']), fn ($c) => Ip6Calc::within($r['start'], $r['end'], $data['real'][$c]['start'], $data['real'][$c]['end'])));
            $p['name'] = $p['route_descr'];
            $p['checked'] = $p['exists'] === null && $p['in_aggregate'] === null && $p['parent'] === null;
        }
        unset($p);
        usort($res['prefixes'], fn ($a, $b) => ($b['family'] <=> $a['family']));

        return $res;
    }

    private function apiRipeImport(int $space, Request $request): array
    {
        try {
            $asn = RipeLookup::parseAsn((string) $request->input('asn', ''));
        } catch (\InvalidArgumentException $e) {
            throw new ApiException($e->getMessage());
        }
        $items = $request->input('items', []);
        if (! is_array($items) || ! $items) {
            throw new ApiException(Lang::t('Zaznacz co najmniej jeden prefiks.'));
        }
        if (count($items) > 200) {
            throw new ApiException(Lang::t('Najwięcej 200 prefiksów naraz.'));
        }
        $desc = trim((string) $request->input('description', '')) ?: "Przydział AS{$asn} (z RIPE)";
        $comment = self::comment($request) ?? "dodano z RIPE dla AS{$asn}";

        $out = [];
        foreach ($items as $it) {
            $cidr = trim((string) (is_array($it) ? ($it['cidr'] ?? '') : $it));
            $name = is_array($it) ? mb_substr(trim((string) ($it['name'] ?? '')), 0, 64) : '';
            try {
                if (! str_contains($cidr, ':') || ! str_contains($cidr, '/')) {
                    throw new ApiException(Lang::t('Tu dodaje się tylko prefiksy IPv6 (IPv4 w zakładce „Adresy IPv4”).'));
                }
                $this->manual()->create($space, $cidr, ['role' => 'aggregate', 'name' => $name !== '' ? $name : "AS{$asn}", 'description' => $desc],
                    $comment, $this->context($space));
                $out[] = ['cidr' => $cidr, 'ok' => true];
            } catch (ApiException $e) {
                $out[] = ['cidr' => $cidr, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        return ['results' => $out, 'added' => count(array_filter($out, fn ($r) => $r['ok']))];
    }
}
