<?php

/*
 * InstallCommand.php - lnms netella-reg:install.
 *
 * Sprawdza dostęp do bazy wtyczki, zakłada schemat, robi pierwszą
 * synchronizację i podaje linię crona. Bazy nie tworzy - na to użytkownik
 * LibreNMS zwykle nie ma uprawnień (polecenie dla administratora bazy
 * jest w komunikacie).
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Console;

use Illuminate\Console\Command;
use Ispnetella\NetellaReg\Core\Db;
use Ispnetella\NetellaReg\Core\ModuleRegistry;
use Ispnetella\NetellaReg\Core\Settings;
use Ispnetella\NetellaReg\Core\SyncRunner;
use Ispnetella\NetellaReg\Plugin;

class InstallCommand extends Command
{
    protected $signature = 'netella-reg:install {--no-sync : bez pierwszej synchronizacji}';

    protected $description = 'Netella-Reg: schemat bazy wtyczki, pierwsza synchronizacja i instrukcja crona';

    public function handle(ModuleRegistry $registry): int
    {
        $this->info('Netella-Reg ' . Plugin::VERSION . ', baza ' . Settings::database());

        if (! Db::available()) {
            $this->error('Brak dostępu do bazy ' . Settings::database() . '. Utwórz ją raz jako administrator bazy:');
            $this->line('  ' . Db::createDatabaseHint());

            return self::FAILURE;
        }

        Db::ensure($registry, true);
        $this->info('Schemat: ' . json_encode(Db::versions()));

        if (! $this->option('no-sync')) {
            (new SyncRunner($registry))->run(null, fn (string $m) => $this->line($m));
        }

        $this->newLine();
        $this->line('Dodaj zadanie crona (np. /etc/cron.d/netella-reg):');
        $this->line('  */5 * * * * librenms /usr/bin/env php /opt/librenms/lnms netella-reg:sync -q >> /opt/librenms/logs/'
            . Plugin::LOG_FILE . ' 2>&1');

        return self::SUCCESS;
    }
}
