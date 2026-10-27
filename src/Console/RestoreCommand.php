<?php

/*
 * RestoreCommand.php - lnms netella-reg:restore <plik> [--with-settings] [--force].
 *
 * Bez --force tylko pokazuje, co jest w kopii i co by się zmieniło.
 * Z --force najpierw robi kopię bieżącego stanu (pre-restore), potem
 * zastępuje zawartość tabel wtyczki zawartością kopii. Ustawienia
 * (uprawnienia, karencja itd.) tylko z --with-settings.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Console;

use Illuminate\Console\Command;
use Ispnetella\NetellaReg\Core\Backup;
use Ispnetella\NetellaReg\Core\Db;
use Ispnetella\NetellaReg\Core\ModuleRegistry;

class RestoreCommand extends Command
{
    protected $signature = 'netella-reg:restore
        {file : nazwa pliku z storage/app/netella-reg albo pełna ścieżka}
        {--with-settings : przywróć też ustawienia wtyczki}
        {--force : wykonaj (bez tej opcji tylko podgląd)}';

    protected $description = 'Netella-Reg: przywrócenie kopii zapasowej (bez --force tylko podgląd)';

    public function handle(ModuleRegistry $registry): int
    {
        $arg = (string) $this->argument('file');
        $path = is_file($arg) ? $arg : Backup::path(basename($arg));
        if ($path === null) {
            $this->error("Nie ma kopii $arg. Lista: lnms netella-reg:backup --list");

            return self::FAILURE;
        }

        try {
            Db::ensure($registry);
            $data = Backup::read($path);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $data['meta']['file'] = basename($path);

        $known = ['core' => Db::CORE_VERSION];
        foreach ($registry->all() as $key => $m) {
            $known[$key] = $m->schemaVersion();
        }

        $this->line('Kopia: ' . basename($path) . ', utworzona ' . ($data['meta']['created_at'] ?? '?')
            . ' przez wersję ' . ($data['meta']['version'] ?? '?') . ', schemat ' . json_encode($data['meta']['schema'] ?? []));
        $db = Db::connection();
        foreach ($data['tables'] as $t => $rows) {
            $now = $db->getSchemaBuilder()->hasTable($t) ? $db->table($t)->count() : 0;
            $this->line(sprintf('  %-20s w kopii %7d, teraz %7d', $t, count($rows), $now));
        }
        $this->line('Ustawienia: ' . ($this->option('with-settings')
            ? ($data['settings'] === [] ? 'kopia nie ma ustawień - bez zmian' : 'zostaną przywrócone')
            : 'bez zmian (dodaj --with-settings, żeby przywrócić)'));

        $problems = Backup::problems((array) ($data['meta']['schema'] ?? []), $known);
        if ($problems !== []) {
            foreach ($problems as $p) {
                $this->error($p);
            }
            $this->error('Zaktualizuj wtyczkę przed przywróceniem tej kopii.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->warn('Podgląd. Żeby przywrócić, dodaj --force (bieżący stan trafi najpierw do kopii pre-restore).');

            return self::SUCCESS;
        }

        try {
            $safety = Backup::create(Backup::PRE_RESTORE);
            $this->line('Kopia bieżącego stanu: ' . $safety['file']);
            $counts = Backup::restore($data, $registry, (bool) $this->option('with-settings'));
        } catch (\Throwable $e) {
            $this->error('Przywracanie przerwane: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Przywrócono ' . array_sum($counts) . ' wierszy z ' . count($counts) . ' tabel.');

        return self::SUCCESS;
    }
}
