<?php

/*
 * BackupCommand.php - lnms netella-reg:backup [--list]. Pełna kopia bazy wtyczki.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Console;

use Illuminate\Console\Command;
use Ispnetella\NetellaReg\Core\Backup;
use Ispnetella\NetellaReg\Core\Db;
use Ispnetella\NetellaReg\Core\ModuleRegistry;

class BackupCommand extends Command
{
    protected $signature = 'netella-reg:backup {--list : tylko lista istniejących kopii}';

    protected $description = 'Netella-Reg: kopia zapasowa bazy wtyczki i ustawień (storage/app/netella-reg)';

    public function handle(ModuleRegistry $registry): int
    {
        if (! $this->option('list')) {
            try {
                Db::ensure($registry);
                $r = Backup::create(Backup::MANUAL);
            } catch (\Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->line('Utworzono ' . Backup::dir() . '/' . $r['file'] . ' (' . round($r['size'] / 1024) . ' KiB)');
            foreach ($r['rows'] as $t => $n) {
                $this->line(sprintf('  %-20s %d wierszy', $t, $n));
            }
        }

        $this->line('Kopie w ' . Backup::dir() . ' (trzymamy ' . Backup::keep() . '):');
        foreach (Backup::list() as $b) {
            $this->line(sprintf('  %s  %6d KiB', $b['file'], round($b['size'] / 1024)));
        }

        return self::SUCCESS;
    }
}
