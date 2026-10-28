<?php

/*
 * Ipam6Module.php - moduł adresacji IPv6 (osobna zakładka).
 *
 * Adresy IPv6 z LibreNMS zbiera moduł adresacji IPv4 (IpamCollector, migawka
 * "ipam/address", family 6), więc ten moduł nie ma własnej synchronizacji -
 * czyta tę migawkę. Wpisy ręczne (prefiksy) są w osobnej tabeli (Ip6Manual),
 * przestrzenie adresowe są wspólne z IPv4 (IpamSpaces).
 *
 * Zasady widoku jak w IPv4: domyślnie w drzewie jest adresacja wewnętrzna
 * (ustawienie "ipam6_private", domyślnie ULA fc00::/7), w której coś leży, oraz
 * prefiksy ręczne/RIPE; reszta LibreNMS (np. połączenia z peerami) za
 * przełącznikiem outside=1. Adresy wyłączonych hostów za przełącznikiem stale=1.
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

    private const VIEW_ACTIONS = ['tree', 'node', 'search', 'manual_list'];

    private const FORM_FIELDS = ['role', 'name', 'description', 'owner', 'vlan', 'devices', 'valid_until'];

    private bool $showStale = true;

    private bool $showOutside = true;

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Adresacja IPv6';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function migrate(Connection $db, int $from): void
    {
        if ($from < 1) {
            IpamSpaces::migrate($db);
            Ip6Manual::migrate($db);
        }
    }

    /** Dane z LibreNMS zbiera moduł IPv4 - tu tylko liczba wpisów do logu. */
    public function sync(SyncContext $ctx): array
    {
        $n = $ctx->db->table(Ip6Manual::TABLE)->whereNull('deleted_at')->count();
        $ctx->log("{$n} prefiksów ręcznych IPv6" . (Settings::moduleEnabled(IpamModule::KEY) ? '' : '; moduł Adresacja IP wyłączony - adresy IPv6 z LibreNMS się nie odświeżają'));

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
            throw new ApiException('Brak rozszerzenia PHP gmp - zainstaluj php-gmp, żeby używać adresacji IPv6.', 501);
        }
        $space = max(1, (int) $request->input('space', IpamSpaces::GLOBAL_ID));
        $view = in_array($action, self::VIEW_ACTIONS, true);
        $this->showStale = ! $view || (string) $request->input('stale', '1') !== '0';
        $this->showOutside = ! $view || (string) $request->input('outside', '1') !== '0';

        return match ($action) {
            'spaces' => ['spaces' => array_values((new IpamSpaces(Db::connection()))->all())],
            'tree' => $this->apiTree($space, trim((string) $request->query('node', ''))),
            'node' => $this->apiNode($space, trim((string) $request->query('cidr', ''))),
            'search' => $this->apiSearch($space, trim((string) $request->query('q', ''))),
            'manual_list' => $this->apiManualList($space),
            'manual_create' => $this->post($request) ?? $this->apiManualCreate($space, $request),
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
            throw new ApiException('Wymagany POST.', 405);
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

    /**
     * Wszystko o przestrzeni: adresy i sieci z LibreNMS, wpisy ręczne, drzewo.
     *
     * @return array{tree:Ip6Tree,addr:array<string,array>,networks:array<string,list<array>>,manual:array<string,array>,hidden:int,suspicious:int,all_addr:array<string,array>}
     */
    private function data(int $space): array
    {
        $spaces = (new IpamSpaces(Db::connection()))->all();
        if (! isset($spaces[$space])) {
            throw new ApiException('Nie ma takiej przestrzeni adresowej.', 404);
        }
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
            $addr[$hex] ??= ['ip' => $ip, 'rows' => [], 'state' => 's'];
            $addr[$hex]['rows'][] = $d + ['state' => $state];
            if ($state === 'l') {
                $addr[$hex]['state'] = 'l';
            }
            if ($len < 128) {
                $networks[Ip6Calc::cidr(Ip6Calc::network($ip, $len), $len)][] = $d + ['state' => $state];
            }
        }

        $manual = [];
        foreach ($this->manual()->active($space) as $m) {
            $manual[$m['cidr']] = $m + Ip6Calc::parse($m['cidr']);
        }
        $implicit = array_map(fn ($c) => Ip6Calc::parse($c), self::implicitPrefixes());
        $covered = function (\GMP $s, \GMP $e) use ($manual, $implicit): bool {
            foreach (array_merge(array_values($manual), $implicit) as $p) {
                if (Ip6Calc::within($p['start'], $p['end'], $s, $e)) {
                    return true;
                }
            }

            return false;
        };

        // Widok: bez wyłączonych hostów (stale=0) i bez adresacji spoza agregatów (outside=0).
        $allAddr = $addr;
        $hidden = 0;
        $view = [];
        foreach ($addr as $hex => $a) {
            if (! $this->showStale && $a['state'] === 's') {
                continue;
            }
            if (! $this->showOutside && ! $covered($a['ip'], $a['ip'])) {
                $hidden++;
                continue;
            }
            $view[$hex] = $a;
        }
        $viewNets = [];
        foreach ($networks as $cidr => $nrows) {
            $active = $this->showStale ? $nrows : array_filter($nrows, fn ($r) => $r['state'] === 'l');
            $c = Ip6Calc::parse($cidr);
            if ($active === [] || (! $this->showOutside && ! $covered($c['start'], $c['end']))) {
                continue;
            }
            $viewNets[$cidr] = array_values($active);
        }

        $prefixes = [];
        foreach ($viewNets as $cidr => $nrows) {
            $c = Ip6Calc::parse($cidr);
            // Rola wpisu ręcznego na tym samym prefiksie wygrywa (agregat = blok, nie sieć).
            $sub = isset($manual[$cidr]) ? $manual[$cidr]['role'] === 'used' : true;
            $prefixes[] = ['start' => $c['start'], 'len' => $c['len'], 'src' => 'lnms', 'subnet' => $sub];
        }
        foreach ($manual as $cidr => $m) {
            $prefixes[] = ['start' => $m['start'], 'len' => $m['len'], 'src' => 'manual', 'subnet' => $m['role'] === 'used'];
        }
        foreach ($implicit as $p) {
            $used = false;
            foreach (array_merge(array_map(fn ($a) => [$a['ip'], $a['ip']], $view), array_map(fn ($m) => [$m['start'], $m['end']], $manual)) as [$s, $e]) {
                if (Ip6Calc::within($p['start'], $p['end'], $s, $e)) {
                    $used = true;
                    break;
                }
            }
            if ($used) {
                $prefixes[] = ['start' => $p['start'], 'len' => $p['len'], 'src' => 'implicit', 'meta' => ['label' => 'adresacja wewnętrzna (ULA)']];
            }
        }

        return ['tree' => new Ip6Tree($prefixes, array_map(fn ($a) => $a['ip'], array_values($view)), self::levels()),
            'addr' => $view, 'all_addr' => $allAddr, 'networks' => $viewNets, 'all_networks' => $networks,
            'manual' => $manual, 'hidden' => $hidden, 'suspicious' => $suspicious, 'covered' => $covered];
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

        return ['ip' => Ip6Calc::gmp2ip($a['ip']), 'n' => $hex, 'state' => $a['state'], 'rows' => array_map([self::class, 'lnmsRow'], $a['rows'])];
    }

    // ---------------------------------------------------------------- API

    private function apiTree(int $space, string $node): array
    {
        $data = $this->data($space);
        $tree = $data['tree'];
        if ($node === '') {
            $children = $tree->roots();
            $addrIdx = $tree->rootAddresses();
            $free = [];
        } else {
            $n = $tree->node($node) ?? throw new ApiException("Nie ma węzła {$node}.", 404);
            $children = $n['children'];
            $addrIdx = $n['addr'];
            $free = [];
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
            'children' => array_map(fn ($c) => $this->nodeInfo($data, $tree->node($c)), $children),
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
        $n = $data['tree']->node($cidr) ?? throw new ApiException("Nie ma węzła {$cidr}.", 404);

        return [
            'node' => $this->nodeInfo($data, $n),
            'path' => $data['tree']->path($cidr),
            'free_blocks' => $data['tree']->freeBlocks($cidr, 30),
            'history' => AuditLog::page(['module' => self::KEY, 'key_prefix' => ["n:{$cidr}:"]], 1, 100)['rows'],
            'roles' => Ip6Manual::PREFIX_ROLES,
        ];
    }

    private function apiSearch(int $space, string $q): array
    {
        try {
            $p = Ip6Calc::parse($q);
        } catch (\InvalidArgumentException) {
            throw new ApiException('Wpisz adres albo prefiks IPv6, np. 2a0e:39c0::1 albo 2a0e:39c0::/32.');
        }
        $data = $this->data($space);
        $tree = $data['tree'];
        if ($p['len'] === 128) {
            $hex = $p['start_hex'];
            $node = $tree->deepest($p['start']);
            $known = isset($data['all_addr'][$hex]);

            return ['kind' => 'address', 'ip' => Ip6Calc::gmp2ip($p['start']), 'path' => $node ? $tree->path($node) : [],
                'address' => $known ? $this->addrInfo($data, $hex) : null, 'hidden' => $known && ! isset($data['addr'][$hex])];
        }
        $node = $tree->node($p['cidr']) ? $p['cidr'] : $tree->deepest($p['start']);

        return ['kind' => 'prefix', 'cidr' => $p['cidr'], 'exact' => $node === $p['cidr'], 'path' => $node ? $tree->path($node) : []];
    }

    private function apiManualList(int $space): array
    {
        return ['rows' => array_map([self::class, 'publicManual'], array_values($this->data($space)['manual'])), 'roles' => Ip6Manual::PREFIX_ROLES];
    }

    /** @return array{0:callable,1:callable} sprawdzenia blokad z pełnych danych LibreNMS */
    private function checks(int $space): array
    {
        $saved = [$this->showStale, $this->showOutside];
        [$this->showStale, $this->showOutside] = [true, true];
        $data = $this->data($space);
        [$this->showStale, $this->showOutside] = $saved;

        return [
            fn (string $cidr) => isset($data['all_networks'][$cidr]),
            fn (\GMP $s, \GMP $e) => count(array_filter($data['all_addr'], fn ($a) => gmp_cmp($a['ip'], $s) >= 0 && gmp_cmp($a['ip'], $e) <= 0)),
        ];
    }

    private function apiManualCreate(int $space, Request $request): array
    {
        [$net, $inside] = $this->checks($space);

        return $this->manual()->create($space, (string) $request->input('target', ''), $request->only(self::FORM_FIELDS),
            self::comment($request), $net, $inside);
    }

    private function apiManualUpdate(Request $request): array
    {
        $entry = $this->manual()->find((int) $request->input('id', 0)) ?? throw new ApiException('Nie ma takiego wpisu (mógł zostać usunięty).', 404);
        [$net, $inside] = $this->checks($entry['space_id']);

        return ['entry' => $this->manual()->update($entry['id'], $request->only(self::FORM_FIELDS), self::comment($request), $net, $inside)];
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
        $data = $this->data($space);
        [$this->showStale, $this->showOutside] = $saved;

        $v6 = [];
        foreach ($res['prefixes'] as $p) {
            if ($p['family'] === 6) {
                $v6[$p['cidr']] = Ip6Calc::parse($p['cidr']);
            }
        }
        foreach ($res['prefixes'] as &$p) {
            $p['sources_label'] = array_map(fn ($s) => RipeLookup::SOURCES[$s] ?? $s, $p['sources']);
            if ($p['family'] !== 6) {
                $p += ['unsupported' => true, 'unsupported_label' => 'IPv4 - dodaj w zakładce „Adresacja IP”', 'checked' => false,
                    'exists' => null, 'in_aggregate' => null, 'parent' => null, 'lnms' => 0, 'name' => null];
                continue;
            }
            $r = $v6[$p['cidr']];
            $p['unsupported'] = false;
            $p['exists'] = isset($data['manual'][$p['cidr']]) ? (Ip6Manual::PREFIX_ROLES[$data['manual'][$p['cidr']]['role']] ?? 'wpis') : null;
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
            $p['lnms'] = count(array_filter(array_keys($data['all_networks']), function ($c) use ($r) {
                $n = Ip6Calc::parse($c);

                return Ip6Calc::within($r['start'], $r['end'], $n['start'], $n['end']);
            }));
            $p['name'] = $p['route_descr'];
            $p['checked'] = $p['exists'] === null && $p['in_aggregate'] === null && $p['parent'] === null;
        }
        unset($p);
        // IPv6 na górze listy.
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
            throw new ApiException('Zaznacz co najmniej jeden prefiks.');
        }
        if (count($items) > 200) {
            throw new ApiException('Najwięcej 200 prefiksów naraz.');
        }
        $desc = trim((string) $request->input('description', '')) ?: "Przydział AS{$asn} (z RIPE)";
        $comment = self::comment($request) ?? "dodano z RIPE dla AS{$asn}";
        [$net, $inside] = $this->checks($space);

        $out = [];
        foreach ($items as $it) {
            $cidr = trim((string) (is_array($it) ? ($it['cidr'] ?? '') : $it));
            $name = is_array($it) ? mb_substr(trim((string) ($it['name'] ?? '')), 0, 64) : '';
            try {
                if (! str_contains($cidr, ':')) {
                    throw new ApiException('IPv4 dodaje się w zakładce „Adresacja IP”.');
                }
                $this->manual()->create($space, $cidr, ['role' => 'aggregate', 'name' => $name !== '' ? $name : "AS{$asn}", 'description' => $desc],
                    $comment, $net, $inside);
                $out[] = ['cidr' => $cidr, 'ok' => true];
            } catch (ApiException $e) {
                $out[] = ['cidr' => $cidr, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        return ['results' => $out, 'added' => count(array_filter($out, fn ($r) => $r['ok']))];
    }
}
