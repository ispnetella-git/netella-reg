<?php

/*
 * SettingsWatcher.php - wpis w dzienniku przy każdej zmianie ustawień.
 *
 * Ustawienia zapisuje kontroler LibreNMS (plugin.update), do którego wtyczka
 * nie ma haka. Dlatego porównujemy zapisany JSON z ostatnio widzianym
 * (reg_state "settings.seen"). Po zapisie LibreNMS przekierowuje z powrotem
 * na stronę ustawień, więc zmianę wykrywa hook Settings w żądaniu tego
 * samego administratora i to on trafia do dziennika. Jeśli pierwszy zauważy
 * ją cron, wpis ma źródło "sync" i nie ma autora.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Core;

final class SettingsWatcher
{
    private const STATE_KEY = 'settings.seen';

    public static function check(bool $byUser): void
    {
        try {
            Settings::reset();
            $now = Settings::all();
            ksort($now);
            $seen = Db::state(self::STATE_KEY);

            if (! is_array($seen)) {
                Db::setState(self::STATE_KEY, $now);

                return;
            }

            if (SnapshotDiff::hash($seen) === SnapshotDiff::hash($now)) {
                return;
            }

            [$before, $after] = SnapshotDiff::changedFields($seen, $now);

            if ($byUser) {
                AuditLog::user('core', 'settings', 'settings', AuditLog::SETTINGS, $before, $after,
                    'zmiana wykryta po zapisie ustawień');
            } else {
                AuditLog::insertMany([AuditLog::row('core', 'settings', 'settings', AuditLog::SETTINGS,
                    AuditLog::SOURCE_SYNC, $before, $after, 'zmiana wykryta przez synchronizację')]);
            }

            Db::setState(self::STATE_KEY, $now);
        } catch (\Throwable) {
            // brak bazy wtyczki - nic do zapisania
        }
    }
}
