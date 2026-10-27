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
use Ispnetella\NetellaReg\Core\Expiry;
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

    /** Pola formularza wpisu ręcznego. */
    private const FORM_FIELDS = ['name', 'description', 'owner', 'status', 'devices', Expiry::FIELD];

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
        return 4;
    }

    public function migrate(Connection $db, int $from): void
    {
        // 1: tylko tabele rdzenia, 2: reg_vlan_manual, 3: grupy (batch_id) i scalenie dziennika,
        // 4: termin ważności (valid_until, expired_at).
        VlanManual::migrate($db, $from);
    }

    public function sync(SyncContext $ctx): array
    {
        $current = (new VlanCollector())->collect();
        $ctx->log(count($current) . ' wystąpień VLAN-ów w LibreNMS');

        $summary = $ctx->snapshot->apply(self::KEY, self::TYPE, $current, $ctx->now);

        // Wpisy ręczne, których numer właśnie pojawił się w LibreNMS.
        $index = $this->index($ctx->now);
        $summary['adopted'] = $this->manualRepo()->adopt(fn (int $v) => $index->inLibreNms($v), $ctx->now);
        $summary['expired'] = $this->manualRepo()->expire($ctx->now);

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

    /** Lista wpisów ręcznych: jedna pozycja na grupę dodaną jednym kliknięciem. */
    private function apiManualList(): array
    {
        $index = $this->index();
        $rows = array_map(function (array $g) use ($index) {
            // Stan grupy teraz: statusy numerów z ich liczbą, np. "wpis ręczny 85, LibreNMS 2".
            $now = [];
            $adopted = 0;
            foreach ($g['vlans_list'] as $v) {
                $label = VlanIndex::LABELS[$index->status($v)];
                $now[$label] = ($now[$label] ?? 0) + 1;
                $adopted += empty($index->manual($v)['adopted_at']) ? 0 : 1;
            }
            unset($g['vlans_list']);

            return $g + ['current' => $now, 'adopted_count' => $adopted];
        }, $this->manualRepo()->grouped());

        return ['rows' => $rows, 'status_labels' => VlanManual::STATUS_LABELS];
    }

    private function apiManualCreate(Request $request): array
    {
        $this->requirePost($request);
        $index = $this->index();
        $res = $this->manualRepo()->create(
            (string) $request->input('range', ''),
            $request->only(self::FORM_FIELDS),
            self::comment($request),
            fn (int $v) => $index->knownToLibreNms($v)
        );

        return [
            'created' => VlanRange::compress($res['created']),
            'count' => count($res['created']),
        ];
    }

    private function apiManualUpdate(Request $request): array
    {
        $this->requirePost($request);
        $row = $this->manualRepo()->update(
            (int) $request->input('id', 0),
            $request->only(self::FORM_FIELDS),
            self::comment($request),
            $request->boolean('group')
        );

        return ['entry' => $row];
    }

    private function apiManualDelete(Request $request): array
    {
        $this->requirePost($request);
        $vlans = $this->manualRepo()->delete((int) $request->input('id', 0), self::comment($request), $request->boolean('group'));

        return ['deleted' => VlanRange::compress($vlans), 'count' => count($vlans)];
    }

    private function manualRepo(): VlanManual
    {
        return new VlanManual(Db::connection());
    }

    /** Grupa wpisu (numery i ich liczba) albo null, gdy wpis jest pojedynczy. */
    private function groupOf(?array $entry): ?array
    {
        if ($entry === null || empty($entry['batch_id'])) {
            return null;
        }
        $vlans = Db::connection()->table(VlanManual::TABLE)->where('batch_id', $entry['batch_id'])
            ->whereNull('deleted_at')->orderBy('vlan')->pluck('vlan')->map(fn ($v) => (int) $v)->all();

        return count($vlans) > 1 ? ['vlans' => VlanRange::compress($vlans), 'count' => count($vlans)] : null;
    }

    /**
     * Historia numeru: jego własne wpisy i wpisy grupowe, których lista "vlans"
     * go zawiera. Zakres range_from-range_to to tylko przybliżenie (lista może
     * mieć dziury, np. "100,105"), więc dokładne sprawdzenie jest tutaj.
     */
    private static function historyOf(int $vlan): array
    {
        $rows = AuditLog::page(['module' => self::KEY, 'key_prefix' => "v{$vlan}:", 'covers' => $vlan], 1, 200)['rows'];

        return array_values(array_filter($rows, function (array $r) use ($vlan) {
            $list = $r['after']['vlans'] ?? $r['before']['vlans'] ?? null;
            if ($list === null || $r['range_from'] === null) {
                return true;
            }
            try {
                return in_array($vlan, VlanRange::parse((string) $list), true);
            } catch (\InvalidArgumentException) {
                return true;
            }
        }));
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
            'expired' => $index->expiredMask(),
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
            'manual_group' => $this->groupOf($index->manual($vlan)),
            'status_labels' => VlanManual::STATUS_LABELS,
            'occurrences' => $occ,
            'history' => self::historyOf($vlan),
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
                    'manual' => $m === null ? null : ['description' => $m['description'], 'owner' => $m['owner'], 'devices' => $m['devices'],
                        'valid_until' => $m['valid_until'], 'expiry' => $m['expiry']],
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
