<?php

/*
 * SyncCommand.php - lnms netella-reg:sync [moduł]. Uruchamiany z crona co 5 minut.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Console;

use Illuminate\Console\Command;
use Ispnetella\NetellaReg\Core\Backup;
use Ispnetella\NetellaReg\Core\ModuleRegistry;
use Ispnetella\NetellaReg\Core\SettingsWatcher;
use Ispnetella\NetellaReg\Core\SyncRunner;
use Ispnetella\NetellaReg\Plugin;

class SyncCommand extends Command
{
    protected $signature = 'netella-reg:sync {module? : tylko ten moduł, np. vlan}';

    protected $description = 'Netella-Reg: synchronizacja z bazą LibreNMS i zapis zmian w dzienniku';

    public function handle(ModuleRegistry $registry): int
    {
        // Jak w PppoeAsr: ustawienia wyzerowane (np. po przeinstalowaniu pakietu
        // przez daily.sh) wracają z kopii, zanim cokolwiek je przeczyta.
        if (Backup::restoreSettingsIfLost() !== null) {
            $this->warn(date('Y-m-d H:i:s') . ' Ustawienia wtyczki były puste - przywrócono z ' . Backup::settingsPath());
        }

        if (! app(\LibreNMS\Interfaces\Plugins\PluginManagerInterface::class)->pluginEnabled(Plugin::NAME)) {
            $this->line(date('Y-m-d H:i:s') . ' Wtyczka ' . Plugin::NAME . ' jest wyłączona - pomijam.');

            return self::SUCCESS;
        }

        $only = $this->argument('module');
        if ($only !== null && $registry->enabledModule($only) === null) {
            $this->error("Moduł '$only' nie istnieje albo jest wyłączony.");

            return self::FAILURE;
        }

        try {
            $results = (new SyncRunner($registry))->run($only, function (string $m) {
                $this->line(date('Y-m-d H:i:s') . ' ' . $m);
            });
            // Po run(), bo dopiero ono zakłada schemat przy pierwszym uruchomieniu.
            SettingsWatcher::check(false);
        } catch (\Throwable $e) {
            $this->error(date('Y-m-d H:i:s') . ' ' . $e->getMessage());

            return self::FAILURE;
        }

        // Kopie: ustawień przy zmianie, pełna raz na dobę. Błąd kopii nie psuje synchronizacji.
        Backup::backupSettings();
        if ($only === null) {
            try {
                if (($b = Backup::dailyIfDue()) !== null) {
                    $this->line(date('Y-m-d H:i:s') . ' Kopia zapasowa ' . $b['file'] . ' (' . round($b['size'] / 1024) . ' KiB)');
                }
            } catch (\Throwable $e) {
                $this->error(date('Y-m-d H:i:s') . ' Kopia zapasowa nie powstała: ' . $e->getMessage());
            }
        }

        foreach ($results as $r) {
            if (! $r['ok']) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
