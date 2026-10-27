<?php

/*
 * Backup.php - kopie zapasowe wtyczki, wzorowane na wtyczce PppoeAsr.
 *
 * 1. Pełna kopia bazy wtyczki: wszystkie tabele reg_* (wpisy ręczne,
 *    przestrzenie, dziennik, migawki, stan) i ustawienia, w jednym pliku
 *    storage/app/netella-reg/netella-reg-RRRRMMDD-GGMM-<powód>.json.gz.
 *    Raz na dobę robi ją synchronizacja (dailyIfDue), ręcznie: przycisk
 *    w ustawieniach albo lnms netella-reg:backup. Starsze ponad backup_keep
 *    kopii są usuwane. Baza wtyczki jest osobna, więc kopie LibreNMS jej
 *    zwykle nie obejmują.
 *
 * 2. Kopia samych ustawień: storage/app/netella_reg_settings.json,
 *    zapisywana przy każdej synchronizacji, gdy się zmieniły. Gdy ustawienia
 *    wtyczki znikną (np. daily.sh LibreNMS przeinstaluje pakiet i wiersz
 *    plugins ma puste settings), restoreSettingsIfLost() odtwarza je
 *    i zapisuje to w dzienniku.
 *
 * Przywracanie pełnej kopii tylko z konsoli (lnms netella-reg:restore),
 * po kopii bezpieczeństwa bieżącego stanu.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Core;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use Ispnetella\NetellaReg\Plugin;

final class Backup
{
    public const FORMAT = 1;

    public const AUTO = 'auto';
    public const MANUAL = 'manual';
    public const PRE_RESTORE = 'pre-restore';

    /** Nazwa pliku kopii; tylko takie pliki można pobrać i przywrócić. */
    public const FILE_PATTERN = '/^netella-reg-\d{8}-\d{4,6}-(auto|manual|pre-restore)\.json\.gz$/';

    private const STATE_LAST = 'backup.last';

    public static function dir(): string
    {
        return storage_path('app/netella-reg');
    }

    public static function settingsPath(): string
    {
        return storage_path('app/netella_reg_settings.json');
    }

    public static function keep(): int
    {
        return max(1, min(365, (int) Settings::get('backup_keep', 14)));
    }

    /** Ścieżka pliku kopii albo null, gdy nazwa jest niedozwolona lub pliku nie ma. */
    public static function path(string $file): ?string
    {
        if (! preg_match(self::FILE_PATTERN, $file)) {
            return null;
        }
        $p = self::dir() . '/' . $file;

        return is_file($p) ? $p : null;
    }

    /** Tabele wtyczki w jej bazie. */
    private static function tables(Connection $db): array
    {
        $out = [];
        foreach ($db->select('SHOW TABLES') as $row) {
            $t = (string) array_values((array) $row)[0];
            if (str_starts_with($t, 'reg_')) {
                $out[] = $t;
            }
        }
        sort($out);

        return $out;
    }

    /** Ustawienia zapisane w tabeli plugins (bez wartości domyślnych). */
    private static function storedSettings(): array
    {
        $json = \Illuminate\Support\Facades\DB::table('plugins')->where('plugin_name', Plugin::NAME)
            ->orderByDesc('plugin_active')->value('settings');

        return is_string($json) ? (json_decode($json, true) ?: []) : (is_array($json) ? $json : []);
    }

    /**
     * Tworzy pełną kopię. Plik powstaje pod nazwą tymczasową i dopiero gotowy
     * dostaje właściwą, więc przerwana kopia nie udaje poprawnej.
     *
     * @return array{file:string,size:int,rows:array<string,int>}
     */
    public static function create(string $reason = self::MANUAL): array
    {
        $db = Db::connection();
        $dir = self::dir();
        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Nie można utworzyć katalogu kopii $dir");
        }

        $file = 'netella-reg-' . date('Ymd-His') . '-' . $reason . '.json.gz';
        $tmp = $dir . '/.' . $file . '.tmp';
        $gz = gzopen($tmp, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException("Nie można zapisać $tmp");
        }

        $rows = [];
        try {
            $meta = [
                'plugin' => Plugin::NAME,
                'version' => Plugin::VERSION,
                'format' => self::FORMAT,
                'created_at' => date('Y-m-d H:i:s'),
                'reason' => $reason,
                'database' => Settings::database(),
                'schema' => Db::versions(),
            ];
            gzwrite($gz, '{"meta":' . self::json($meta) . ',"settings":' . self::json((object) self::storedSettings()) . ',"tables":{');

            foreach (self::tables($db) as $i => $table) {
                gzwrite($gz, ($i ? ',' : '') . self::json($table) . ':[');
                $n = 0;
                foreach ($db->table($table)->cursor() as $r) {
                    gzwrite($gz, ($n ? ",\n" : "\n") . self::json((array) $r));
                    $n++;
                }
                gzwrite($gz, ']');
                $rows[$table] = $n;
            }
            gzwrite($gz, "}}\n");
        } catch (\Throwable $e) {
            gzclose($gz);
            @unlink($tmp);
            throw $e;
        }
        gzclose($gz);
        @chmod($tmp, 0640);
        rename($tmp, $dir . '/' . $file);

        Db::setState(self::STATE_LAST, ['at' => date('Y-m-d H:i:s'), 'file' => $file, 'reason' => $reason]);
        self::prune();

        return ['file' => $file, 'size' => (int) filesize($dir . '/' . $file), 'rows' => $rows];
    }

    private static function json(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /**
     * Kopie od najnowszej.
     *
     * @return list<array{file:string,size:int,at:string,reason:string}>
     */
    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/netella-reg-*.json.gz') ?: [] as $p) {
            $f = basename($p);
            if (preg_match(self::FILE_PATTERN, $f, $m)) {
                $out[] = ['file' => $f, 'size' => (int) filesize($p), 'at' => date('Y-m-d H:i:s', (int) filemtime($p)), 'reason' => $m[1]];
            }
        }
        usort($out, fn ($a, $b) => strcmp($b['file'], $a['file']));

        return $out;
    }

    /**
     * Usuwa najstarsze kopie automatyczne i ręczne ponad limit. Kopie sprzed
     * przywracania liczą się osobno (zostają 3 ostatnie).
     */
    public static function prune(): void
    {
        $byKind = [];
        foreach (self::list() as $b) {
            $byKind[$b['reason'] === self::PRE_RESTORE ? 'pre' : 'main'][] = $b['file'];
        }
        foreach (['main' => self::keep(), 'pre' => 3] as $kind => $limit) {
            foreach (array_slice($byKind[$kind] ?? [], $limit) as $f) {
                @unlink(self::dir() . '/' . $f);
            }
        }
    }

    /**
     * Kopia automatyczna, jeśli dziś nie powstała jeszcze żadna (automatyczna
     * ani ręczna). Wołane z synchronizacji. Data z nazwy pliku.
     */
    public static function dailyIfDue(): ?array
    {
        foreach (self::list() as $b) {
            if (substr($b['file'], 12, 8) === date('Ymd') && $b['reason'] !== self::PRE_RESTORE) {
                return null;
            }
        }

        return self::create(self::AUTO);
    }

    public static function last(): ?array
    {
        $l = Db::state(self::STATE_LAST);

        return is_array($l) ? $l : null;
    }

    /**
     * Wczytuje kopię (cały plik, do przywracania i sprawdzenia).
     *
     * @return array{meta:array,settings:array,tables:array<string,list<array>>}
     */
    public static function read(string $path): array
    {
        $raw = @gzdecode((string) file_get_contents($path));
        if ($raw === false) {
            throw new \RuntimeException('Plik nie jest poprawnym archiwum gzip.');
        }
        $data = json_decode($raw, true);
        if (! is_array($data) || ($data['meta']['plugin'] ?? null) !== Plugin::NAME || ! is_array($data['tables'] ?? null)) {
            throw new \RuntimeException('To nie jest kopia wtyczki ' . Plugin::NAME . '.');
        }
        if ((int) ($data['meta']['format'] ?? 0) > self::FORMAT) {
            throw new \RuntimeException('Kopia pochodzi z nowszej wersji wtyczki (format ' . $data['meta']['format'] . ').');
        }
        $data['settings'] = is_array($data['settings'] ?? null) ? $data['settings'] : [];

        return $data;
    }

    /**
     * Sprawdza, czy kopię da się przywrócić w tej wersji kodu: schemat kopii
     * nie może być nowszy niż ten, który kod zna.
     *
     * @param  array<string,int>  $known  moduł => wersja schematu w kodzie
     * @return list<string> problemy (pusta lista = można)
     */
    public static function problems(array $backupSchema, array $known): array
    {
        $out = [];
        foreach ($backupSchema as $module => $v) {
            if (isset($known[$module]) && (int) $v > $known[$module]) {
                $out[] = "schemat modułu $module w kopii ($v) jest nowszy niż w tej wersji wtyczki ({$known[$module]})";
            }
        }

        return $out;
    }

    /**
     * Przywraca tabele z kopii: dla każdej tabeli z kopii usuwa bieżące wiersze
     * i wstawia te z kopii (tylko kolumny, które tabela ma). Tabele reg_schema
     * też, a potem Db::ensure() dociąga schemat do bieżącej wersji kodu.
     * Ustawienia tylko na życzenie ($withSettings).
     *
     * @return array<string,int> tabela => liczba wstawionych wierszy
     */
    public static function restore(array $data, ModuleRegistry $registry, bool $withSettings): array
    {
        $db = Db::connection();
        $schema = $db->getSchemaBuilder();
        $counts = [];

        // Najpierw reg_schema, żeby brakujące tabele założyła migracja z wersji kopii.
        $tables = $data['tables'];
        uksort($tables, fn ($a, $b) => [$a !== Db::SCHEMA, $a] <=> [$b !== Db::SCHEMA, $b]);

        $db->transaction(function () use ($db, $schema, $tables, &$counts) {
            foreach ($tables as $table => $rows) {
                if (! preg_match('/^reg_[a-z0-9_]+$/', (string) $table) || ! $schema->hasTable($table)) {
                    continue;
                }
                $cols = array_flip($schema->getColumnListing($table));
                $db->table($table)->delete();
                foreach (array_chunk($rows, 500) as $chunk) {
                    $db->table($table)->insert(array_map(fn ($r) => array_intersect_key($r, $cols), $chunk));
                }
                $counts[$table] = count($rows);
            }
        });

        Db::ensure($registry, true);

        if ($withSettings && $data['settings'] !== []) {
            self::writeSettings($data['settings']);
        }

        AuditLog::insertMany([AuditLog::row('core', 'backup', 'backup', AuditLog::RESTORE, AuditLog::SOURCE_USER, null,
            ['file' => $data['meta']['file'] ?? null, 'created_at' => $data['meta']['created_at'] ?? null,
                'rows' => array_sum($counts), 'settings' => $withSettings],
            'przywrócono kopię z ' . ($data['meta']['created_at'] ?? '?') . ' (konsola)', null, get_current_user() ?: null)]);

        return $counts;
    }

    private static function writeSettings(array $settings): void
    {
        \Illuminate\Support\Facades\DB::table('plugins')->where('plugin_name', Plugin::NAME)
            ->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE)]);
        Settings::reset();
    }

    // ------------------------------------------------------------- kopia ustawień

    /** Ustawienia wyglądają na zapisane przez formularz (a nie puste/wyzerowane). */
    public static function settingsLookConfigured(array $s): bool
    {
        return array_key_exists('grace_days', $s) || array_key_exists('perm_view_roles', $s);
    }

    /** Zapisuje kopię ustawień, gdy się zmieniły. Pustymi nie nadpisuje dobrej kopii. */
    public static function backupSettings(): void
    {
        try {
            $s = self::storedSettings();
            if (! self::settingsLookConfigured($s)) {
                return;
            }
            ksort($s);
            $json = json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $path = self::settingsPath();
            if ($json === false || (is_file($path) && md5_file($path) === md5($json . "\n"))) {
                return;
            }
            if (file_put_contents($path, $json . "\n") !== false) {
                @chmod($path, 0640);
            }
        } catch (\Throwable) {
            // spróbujemy w kolejnym przebiegu
        }
    }

    /**
     * Gdy ustawienia wtyczki zniknęły, a kopia ustawień jest dobra - odtwarza je.
     * Zwraca odtworzone ustawienia albo null.
     */
    public static function restoreSettingsIfLost(): ?array
    {
        try {
            $exists = \Illuminate\Support\Facades\DB::table('plugins')->where('plugin_name', Plugin::NAME)->exists();
            $path = self::settingsPath();
            if (! $exists || self::settingsLookConfigured(self::storedSettings()) || ! is_file($path)) {
                return null;
            }
            $backup = json_decode((string) file_get_contents($path), true);
            if (! is_array($backup) || ! self::settingsLookConfigured($backup)) {
                return null;
            }

            self::writeSettings($backup);
            Log::warning('Netella-Reg: ustawienia wtyczki były puste - przywrócono z ' . $path);
            try {
                AuditLog::insertMany([AuditLog::row('core', 'settings', 'settings', AuditLog::SETTINGS, AuditLog::SOURCE_SYNC,
                    null, $backup, 'ustawienia były puste - przywrócono z kopii ' . basename($path))]);
                // Watcher nie powinien zapisać tego drugi raz jako zwykłej zmiany.
                $seen = Settings::all();
                ksort($seen);
                Db::setState('settings.seen', $seen);
            } catch (\Throwable) {
                // brak bazy wtyczki - samo przywrócenie wystarczy
            }

            return $backup;
        } catch (\Throwable $e) {
            try {
                Log::error('Netella-Reg: nie udało się przywrócić ustawień z kopii: ' . $e->getMessage());
            } catch (\Throwable) {
            }

            return null;
        }
    }
}
