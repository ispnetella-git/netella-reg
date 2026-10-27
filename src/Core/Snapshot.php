<?php

/*
 * Snapshot.php - migawka stanu LibreNMS i wykrywanie zmian.
 *
 * Moduł podaje bieżący stan jako klucz => dane. Snapshot porównuje go
 * z poprzednim przebiegiem, zapisuje różnice w reg_snapshot i dopisuje
 * do dziennika zdarzenia lnms_appeared / lnms_changed / lnms_gone.
 *
 * Rekord, który zniknął, zostaje w migawce z datą gone_at - moduł decyduje,
 * czy w okresie karencji liczy się jeszcze jako zajęty. Gdy wróci, dostaje
 * zdarzenie lnms_appeared z poprzednim stanem w polu before.
 *
 * Pierwszy import (pusta migawka dla danego typu) daje jeden wpis
 * initial_import zamiast setek pojedynczych.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Core;

use Illuminate\Database\Connection;

final class Snapshot
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param  array<string,array>  $current
     * @return array{appeared:int,changed:int,gone:int,returned:int,total:int,initial:bool}
     */
    public function apply(string $module, string $type, array $current, \DateTimeImmutable $now): array
    {
        $ts = $now->format('Y-m-d H:i:s');

        $previous = [];
        foreach ($this->db->table(Db::SNAPSHOT)
            ->where('module', $module)->where('entity_type', $type)
            ->get(['entity_key', 'hash', 'data', 'gone_at']) as $r) {
            $previous[(string) $r->entity_key] = [
                'hash' => (string) $r->hash,
                'gone' => $r->gone_at !== null,
                'data' => json_decode((string) $r->data, true) ?: [],
            ];
        }

        $initial = $previous === [];
        $diff = SnapshotDiff::compute($previous, $current);
        $audit = [];

        $this->db->transaction(function () use ($module, $type, $diff, $ts, $now, $initial, &$audit) {
            $inserts = [];
            foreach ($diff['appeared'] as $key => $data) {
                $inserts[] = [
                    'module' => $module,
                    'entity_type' => $type,
                    'entity_key' => (string) $key,
                    'hash' => SnapshotDiff::hash($data),
                    'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                    'first_seen' => $ts,
                    'last_seen' => $ts,
                    'gone_at' => null,
                ];
                if (! $initial) {
                    $audit[] = AuditLog::row($module, $type, (string) $key, AuditLog::LNMS_APPEARED,
                        AuditLog::SOURCE_SYNC, null, $data, null, null, null, null, $now);
                }
            }
            foreach (array_chunk($inserts, 500) as $chunk) {
                $this->db->table(Db::SNAPSHOT)->insert($chunk);
            }

            foreach ($diff['changed'] as $key => [$before, $after]) {
                $this->update($module, $type, (string) $key, $after, $ts);
                [$b, $a] = SnapshotDiff::changedFields($before, $after);
                $audit[] = AuditLog::row($module, $type, (string) $key, AuditLog::LNMS_CHANGED,
                    AuditLog::SOURCE_SYNC, $b, $a, null, null, null, null, $now);
            }

            foreach ($diff['returned'] as $key => [$before, $after]) {
                $this->update($module, $type, (string) $key, $after, $ts);
                $audit[] = AuditLog::row($module, $type, (string) $key, AuditLog::LNMS_APPEARED,
                    AuditLog::SOURCE_SYNC, $before, $after, 'wrócił po zniknięciu', null, null, null, $now);
            }

            foreach (array_chunk(array_keys($diff['gone']), 500) as $keys) {
                $this->db->table(Db::SNAPSHOT)->where('module', $module)->where('entity_type', $type)
                    ->whereIn('entity_key', array_map('strval', $keys))
                    ->update(['gone_at' => $ts]);
            }
            foreach ($diff['gone'] as $key => $before) {
                $audit[] = AuditLog::row($module, $type, (string) $key, AuditLog::LNMS_GONE,
                    AuditLog::SOURCE_SYNC, $before, null, null, null, null, null, $now);
            }

            // Wszystko, co nadal jest w LibreNMS, było widziane teraz.
            $this->db->table(Db::SNAPSHOT)
                ->where('module', $module)->where('entity_type', $type)->whereNull('gone_at')
                ->update(['last_seen' => $ts]);

            if ($initial && $diff['appeared'] !== []) {
                $audit[] = AuditLog::row($module, $type, '*', AuditLog::INITIAL_IMPORT,
                    AuditLog::SOURCE_SYNC, null, ['count' => count($diff['appeared'])], null, null, null, null, $now);
            }

            AuditLog::insertMany($audit);
        });

        return [
            'appeared' => $initial ? 0 : count($diff['appeared']),
            'changed' => count($diff['changed']),
            'gone' => count($diff['gone']),
            'returned' => count($diff['returned']),
            'total' => count($current),
            'initial' => $initial && $diff['appeared'] !== [],
        ];
    }

    private function update(string $module, string $type, string $key, array $data, string $ts): void
    {
        $this->db->table(Db::SNAPSHOT)
            ->where('module', $module)->where('entity_type', $type)->where('entity_key', $key)
            ->update([
                'hash' => SnapshotDiff::hash($data),
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'last_seen' => $ts,
                'gone_at' => null,
            ]);
    }

    /**
     * Wiersze migawki z rozpakowanymi danymi.
     *
     * @return list<array{key:string,data:array,first_seen:string,last_seen:string,gone_at:?string}>
     */
    public function rows(string $module, string $type, ?callable $scope = null): array
    {
        $q = $this->db->table(Db::SNAPSHOT)->where('module', $module)->where('entity_type', $type);
        if ($scope !== null) {
            $scope($q);
        }

        $out = [];
        foreach ($q->orderBy('entity_key')->get(['entity_key', 'data', 'first_seen', 'last_seen', 'gone_at']) as $r) {
            $out[] = [
                'key' => (string) $r->entity_key,
                'data' => json_decode((string) $r->data, true) ?: [],
                'first_seen' => (string) $r->first_seen,
                'last_seen' => (string) $r->last_seen,
                'gone_at' => $r->gone_at === null ? null : (string) $r->gone_at,
            ];
        }

        return $out;
    }
}
