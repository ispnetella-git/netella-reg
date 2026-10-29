<?php

/*
 * SyncRunner.php - jeden przebieg synchronizacji wszystkich włączonych modułów.
 *
 * Wołany z crona (lnms netella-reg:sync co 5 minut) albo przyciskiem
 * "Synchronizuj teraz". Blokada GET_LOCK pilnuje, żeby dwa przebiegi nie
 * szły naraz - drugi kończy się od razu z komunikatem.
 *
 * Błąd jednego modułu nie zatrzymuje pozostałych. Wynik każdego modułu
 * trafia do reg_state pod kluczem sync.<moduł>.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Core;

use Illuminate\Support\Facades\Log;

final class SyncRunner
{
    private const LOCK = 'netella_reg_sync';

    public function __construct(private readonly ModuleRegistry $registry)
    {
    }

    /**
     * @param  callable(string):void|null  $out
     * @return array<string,array> wynik per moduł
     */
    public function run(?string $only = null, ?callable $out = null): array
    {
        $out ??= static function (string $m): void {
        };

        Db::ensure($this->registry);
        $db = Db::connection();

        $locked = (int) ($db->selectOne('SELECT GET_LOCK(?, 0) AS l', [self::LOCK])->l ?? 0);
        if ($locked !== 1) {
            $out('Inna synchronizacja jest w toku - pomijam.');

            return [];
        }

        $results = [];

        try {
            foreach ($this->registry->enabled() as $key => $module) {
                if ($only !== null && $only !== $key) {
                    continue;
                }

                $start = microtime(true);
                $now = new \DateTimeImmutable();
                $ctx = new SyncContext($now, $db, new Snapshot($db), fn (string $m) => $out("[$key] $m"));

                try {
                    $summary = $module->sync($ctx);
                    $result = ['ok' => true, 'at' => $now->format('Y-m-d H:i:s'),
                        'ms' => (int) round((microtime(true) - $start) * 1000), 'summary' => $summary];
                } catch (\Throwable $e) {
                    Log::error("Netella-Reg sync $key: " . $e->getMessage());
                    $result = ['ok' => false, 'at' => $now->format('Y-m-d H:i:s'),
                        'ms' => (int) round((microtime(true) - $start) * 1000), 'error' => $e->getMessage()];
                }

                Db::setState('sync.' . $key, $result);
                $results[$key] = $result;
                $out("[$key] " . ($result['ok']
                    ? 'OK ' . $result['ms'] . ' ms ' . json_encode($result['summary'], JSON_UNESCAPED_UNICODE)
                    : Lang::t('BŁĄD {error}', ['error' => $result['error']])));
            }
        } finally {
            $db->select('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }

        return $results;
    }

    /** Ostatni wynik synchronizacji modułu z reg_state. */
    public static function last(string $key): ?array
    {
        $v = Db::state('sync.' . $key);

        return is_array($v) ? $v : null;
    }
}
