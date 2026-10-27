<?php

/*
 * VlanModule.php - moduł VLAN: zajętość numerów 1-4094 w całej sieci.
 *
 * Dane z LibreNMS przez migawkę (Core\Snapshot) i wpisy ręczne (VlanManual):
 * mapa numerów, sprawdzanie zakresu, szukanie wolnych numerów, dodawanie,
 * edycja i usuwanie wpisów ręcznych, przejmowanie ich przez LibreNMS
 * i historia zmian.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Vlan;

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

final class VlanModule extends AbstractModule
{
    public const KEY = 'vlan';

    /** Typ rekordu w migawce: wystąpienie VLAN-u na urządzeniu. */
    public const TYPE = 'occurrence';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'VLAN-y';
    }

    public function schemaVersion(): int
    {
        return 2;
    }

    public function migrate(Connection $db, int $from): void
    {
        // Wersja 1 korzystała tylko z tabel rdzenia (reg_snapshot, reg_audit).
        if ($from < 2) {
            VlanManual::migrate($db);
        }
    }

    public function sync(SyncContext $ctx): array
    {
        $current = (new VlanCollector())->collect();
        $ctx->log(count($current) . ' wystąpień VLAN-ów w LibreNMS');

        $summary = $ctx->snapshot->apply(self::KEY, self::TYPE, $current, $ctx->now);

        // Wpisy ręczne, których numer właśnie pojawił się w LibreNMS.
        $index = $this->index($ctx->now);
        $summary['adopted'] = $this->manualRepo()->adopt(fn (int $v) => $index->inLibreNms($v), $ctx->now);

        return $summary;
    }

    /** Usuwanie wpisów wymaga prawa "usuwanie", pozostałe zmiany "edycja". */
    public function apiPermission(string $action, string $method): string
    {
        return match ($action) {
            'manual_delete' => Access::DELETE,
            'manual_create', 'manual_update' => Access::EDIT,
            default => Access::VIEW,
        };
    }

    public function tabView(): ?string
    {
        return 'modules.vlan.tab';
    }

    public function api(string $action, Request $request): array
    {
        return match ($action) {
            'map' => $this->apiMap(),
            'detail' => $this->apiDetail((int) $request->query('vlan', 0)),
            'check' => $this->apiCheck((string) $request->query('range', '')),
            'free' => $this->apiFree(
                (int) $request->query('count', 1),
                (int) $request->query('from', VlanName::MIN),
                (int) $request->query('to', VlanName::MAX)
            ),
            'manual_list' => $this->apiManualList(),
            'manual_create' => $this->apiManualCreate($request),
            'manual_update' => $this->apiManualUpdate($request),
            'manual_delete' => $this->apiManualDelete($request),
            default => parent::api($action, $request),
        };
    }

    private function requirePost(Request $request): void
    {
        if (! $request->isMethod('POST')) {
            throw new ApiException('Wymagany POST.', 405);
        }
    }

    private static function comment(Request $request): ?string
    {
        $c = trim((string) $request->input('comment', ''));

        return $c === '' ? null : mb_substr($c, 0, 1000);
    }

    private function apiManualList(): array
    {
        $index = $this->index();
        $rows = array_values(array_map(fn (array $m) => $m + [
            'current' => $index->status($m['vlan']),
            'current_label' => VlanIndex::LABELS[$index->status($m['vlan'])],
        ], $this->manualRepo()->active()));

        return ['rows' => $rows, 'status_labels' => VlanManual::STATUS_LABELS];
    }

    private function apiManualCreate(Request $request): array
    {
        $this->requirePost($request);
        $index = $this->index();
        $res = $this->manualRepo()->create(
            (string) $request->input('range', ''),
            $request->only(['name', 'description', 'owner', 'status', 'devices']),
            self::comment($request),
            fn (int $v) => $index->inLibreNms($v)
        );

        return [
            'created' => VlanRange::compress($res['created']),
            'count' => count($res['created']),
            'already_in_librenms' => VlanRange::compress($res['already_in_librenms']),
        ];
    }

    private function apiManualUpdate(Request $request): array
    {
        $this->requirePost($request);
        $row = $this->manualRepo()->update(
            (int) $request->input('id', 0),
            $request->only(['name', 'description', 'owner', 'status', 'devices']),
            self::comment($request)
        );

        return ['entry' => $row];
    }

    private function apiManualDelete(Request $request): array
    {
        $this->requirePost($request);
        $this->manualRepo()->delete((int) $request->input('id', 0), self::comment($request));

        return ['deleted' => true];
    }

    private function manualRepo(): VlanManual
    {
        return new VlanManual(Db::connection());
    }

    public function deviceOverview(int $deviceId): ?array
    {
        $rows = $this->snapshot()->rows(self::KEY, self::TYPE, function ($q) use ($deviceId) {
            $q->where('entity_key', 'like', "v%:d{$deviceId}")->whereNull('gone_at');
        });

        if ($rows === []) {
            return null;
        }

        usort($rows, fn ($a, $b) => $a['data']['vlan'] <=> $b['data']['vlan']);

        return [
            'view' => 'modules.vlan.device-overview',
            'data' => [
                'vlanRows' => $rows,
                'vlanList' => VlanRange::compress(array_map(fn ($r) => (int) $r['data']['vlan'], $rows)),
            ],
        ];
    }

    private function snapshot(): Snapshot
    {
        return new Snapshot(Db::connection());
    }

    private function index(?\DateTimeImmutable $now = null): VlanIndex
    {
        return new VlanIndex(
            $this->snapshot()->rows(self::KEY, self::TYPE),
            $now ?? new \DateTimeImmutable(),
            Settings::graceDays(),
            Settings::disabledMode(),
            $this->manualRepo()->active()
        );
    }

    private function apiMap(): array
    {
        $index = $this->index();

        return [
            'statuses' => $index->statusString(),
            'manual' => $index->manualMask(),
            'counts' => $index->counts(),
            'labels' => VlanIndex::LABELS,
            'sync' => SyncRunner::last(self::KEY),
            'grace_days' => Settings::graceDays(),
            'disabled_mode' => Settings::disabledMode(),
        ];
    }

    private function apiDetail(int $vlan): array
    {
        if ($vlan < VlanName::MIN || $vlan > VlanName::MAX) {
            throw new ApiException('Numer VLAN poza zakresem 1-4094.');
        }

        $index = $this->index();
        $occ = array_map(function (array $r) {
            return $r['data'] + [
                'state' => $r['state'],
                'state_label' => VlanIndex::LABELS[$r['state']] ?? $r['state'],
                'first_seen' => $r['first_seen'],
                'last_seen' => $r['last_seen'],
                'gone_at' => $r['gone_at'],
                'device_url' => url('device/' . (int) ($r['data']['device_id'] ?? 0)),
            ];
        }, $index->occurrences($vlan));

        usort($occ, fn ($a, $b) => [$a['state'] === VlanIndex::FREE, $a['device']] <=> [$b['state'] === VlanIndex::FREE, $b['device']]);

        $names = array_values(array_unique(array_filter(array_map(
            fn ($o) => $o['state'] !== VlanIndex::FREE ? $o['name'] : null, $occ))));

        return [
            'vlan' => $vlan,
            'status' => $index->status($vlan),
            'status_label' => VlanIndex::LABELS[$index->status($vlan)],
            'names' => $names,
            // Wpis ręczny: przy numerze z LibreNMS to adnotacja, inaczej źródło statusu.
            'manual' => $index->manual($vlan),
            'status_labels' => VlanManual::STATUS_LABELS,
            'occurrences' => $occ,
            'history' => AuditLog::page(['module' => self::KEY, 'key_prefix' => "v{$vlan}:"], 1, 100)['rows'],
        ];
    }

    private function apiCheck(string $range): array
    {
        try {
            $numbers = VlanRange::parse($range);
        } catch (\InvalidArgumentException $e) {
            throw new ApiException($e->getMessage());
        }

        $index = $this->index();
        $byStatus = [];
        $items = [];

        foreach ($numbers as $v) {
            $s = $index->status($v);
            $byStatus[$s][] = $v;
            if ($s !== VlanIndex::FREE) {
                $devices = [];
                foreach ($index->occurrences($v) as $o) {
                    if ($o['state'] !== VlanIndex::FREE) {
                        $devices[] = $o['data']['device'] ?? '?';
                    }
                }
                $m = $index->manual($v);
                $items[] = [
                    'vlan' => $v,
                    'status' => $s,
                    'devices' => array_values(array_unique($devices)),
                    'manual' => $m === null ? null : ['description' => $m['description'], 'owner' => $m['owner'], 'devices' => $m['devices']],
                ];
            }
        }

        $summary = [];
        foreach ($byStatus as $s => $list) {
            $summary[$s] = ['count' => count($list), 'list' => VlanRange::compress($list), 'label' => VlanIndex::LABELS[$s]];
        }

        return [
            'range' => VlanRange::compress($numbers),
            'total' => count($numbers),
            'all_free' => count($byStatus[VlanIndex::FREE] ?? []) === count($numbers),
            'summary' => $summary,
            'occupied' => $items,
        ];
    }

    private function apiFree(int $count, int $from, int $to): array
    {
        if ($count < 1 || $count > VlanName::MAX) {
            throw new ApiException('Liczba numerów musi być z zakresu 1-4094.');
        }

        $index = $this->index();
        $run = VlanRange::findFreeRun(fn (int $v) => $index->isFree($v), $count, $from, $to);

        return [
            'count' => $count,
            'from' => max(VlanName::MIN, $from),
            'to' => min(VlanName::MAX, $to),
            'found' => $run !== null,
            'range' => $run === null ? null : VlanRange::compress($run),
            'first' => $run[0] ?? null,
            'last' => $run === null ? null : end($run),
        ];
    }
}
