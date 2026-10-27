<?php

/*
 * DoctorCommand.php - lnms netella-reg:doctor. Stan wtyczki bez zmian w danych.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Console;

use Illuminate\Console\Command;
use Ispnetella\NetellaReg\Core\Backup;
use Ispnetella\NetellaReg\Core\Db;
use Ispnetella\NetellaReg\Core\ModuleRegistry;
use Ispnetella\NetellaReg\Core\Settings;
use Ispnetella\NetellaReg\Core\SyncRunner;
use Ispnetella\NetellaReg\Plugin;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

class DoctorCommand extends Command
{
    protected $signature = 'netella-reg:doctor';

    protected $description = 'Netella-Reg: diagnostyka (baza, schemat, moduły, ostatnie synchronizacje)';

    public function handle(ModuleRegistry $registry, PluginManagerInterface $pm): int
    {
        $ok = true;
        $this->line('Netella-Reg ' . Plugin::VERSION);
        $this->line('Wtyczka włączona w LibreNMS: ' . ($pm->pluginEnabled(Plugin::NAME) ? 'tak' : 'NIE'));
        $this->line('Baza: ' . Settings::database() . ' - ' . (Db::available() ? 'dostępna' : 'BRAK DOSTĘPU'));

        if (! Db::available()) {
            $this->line('  ' . Db::createDatabaseHint());

            return self::FAILURE;
        }

        $versions = Db::versions();
        $this->line('Schemat rdzenia: ' . ($versions['core'] ?? 0) . ' / ' . Db::CORE_VERSION);

        foreach ($registry->all() as $key => $m) {
            $last = SyncRunner::last($key);
            $have = $versions[$key] ?? 0;
            $line = sprintf('Moduł %-8s %-9s schemat %d/%d', $key,
                Settings::moduleEnabled($key) ? 'włączony' : 'wyłączony', $have, $m->schemaVersion());

            if ($last === null) {
                $line .= ', synchronizacji jeszcze nie było';
            } else {
                $line .= ', ostatnia synchronizacja ' . $last['at'] . ($last['ok'] ? ' OK ' . $last['ms'] . ' ms' : ' BŁĄD: ' . $last['error']);
                if (! $last['ok'] || strtotime($last['at']) < time() - 900) {
                    $ok = false;
                    $line .= '  <- sprawdź cron';
                }
            }

            $this->line($line);
        }

        $backups = Backup::list();
        if ($backups === []) {
            $this->line('Kopie zapasowe: brak (' . Backup::dir() . ')');
        } else {
            $this->line('Kopie zapasowe: ' . count($backups) . ', ostatnia ' . $backups[0]['file'] . ' (' . $backups[0]['at'] . ')');
            if (strtotime($backups[0]['at']) < time() - 2 * 86400) {
                $ok = false;
                $this->line('  <- ostatnia kopia starsza niż 2 dni');
            }
        }
        $this->line('Kopia ustawień: ' . (is_file(Backup::settingsPath()) ? Backup::settingsPath() : 'brak'));

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
