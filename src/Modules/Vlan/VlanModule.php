<?php

/*
 * VlanModule.php - moduł VLAN: zajętość numerów 1-4094 w całej sieci.
 *
 * Wersja 0.1 jest tylko do odczytu: dane z LibreNMS przez migawkę
 * (Core\Snapshot), mapa numerów, sprawdzanie zakresu, szukanie wolnych
 * numerów i historia zmian. Wpisy ręczne i rezerwacje dojdą w 0.2.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Vlan;

use Illuminate\Database\Connection;
use Illuminate\Http\Request;
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
        return 1;
    }

    public function migrate(Connection $db, int $from): void
    {
        // 0.1 korzysta tylko z tabel rdzenia (reg_snapshot, reg_audit).
        // Tabela wpisów ręcznych reg_vlan_manual dojdzie w wersji 2 schematu.
    }

    public function sync(SyncContext $ctx): array
    {
        $current = (new VlanCollector())->collect();
        $ctx->log(count($current) . ' wystąpień VLAN-ów w LibreNMS');

        return $ctx->snapshot->apply(self::KEY, self::TYPE, $current, $ctx->now);
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
            default => parent::api($action, $request),
        };
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

    private function index(): VlanIndex
    {
        return new VlanIndex(
            $this->snapshot()->rows(self::KEY, self::TYPE),
            new \DateTimeImmutable(),
            Settings::graceDays(),
            Settings::disabledMode()
        );
    }

    private function apiMap(): array
    {
        $index = $this->index();

        return [
            'statuses' => $index->statusString(),
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
                $items[] = ['vlan' => $v, 'status' => $s, 'devices' => array_values(array_unique($devices))];
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
