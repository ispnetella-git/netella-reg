<?php

/*
 * Db.php - połączenie z bazą wtyczki i schemat rdzenia.
 *
 * Dane trzymamy w OSOBNEJ bazie (domyślnie librenms_netella_reg), tak jak
 * pozostałe wtyczki Netelli. LibreNMS synchronizuje swoje tabele metodą
 * sync() i usuwa wiersze, których discovery nie wykryło, więc ręczne wpisy
 * i historia nie przetrwałyby w jego schemacie.
 *
 * Bazy nie tworzymy sami: użytkownik bazodanowy LibreNMS zwykle nie ma
 * prawa CREATE DATABASE. Robi to raz administrator (README, sekcja Baza).
 *
 * Schemat ma wersje per moduł w tabeli reg_schema. Rdzeń to moduł "core".
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Core;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
// Alias konieczny: nazwy klas w PHP nie rozróżniają wielkości liter, więc
// fasada DB koliduje z tą klasą Db.
use Illuminate\Support\Facades\DB as LaravelDb;
use Ispnetella\NetellaReg\Plugin;

final class Db
{
    public const SCHEMA = 'reg_schema';
    public const AUDIT = 'reg_audit';
    public const SNAPSHOT = 'reg_snapshot';
    public const STATE = 'reg_state';

    /** Wersja schematu rdzenia. */
    public const CORE_VERSION = 2;

    private static bool $ensured = false;

    public static function connection(): Connection
    {
        $name = Plugin::CONNECTION;
        $database = Settings::database();

        // Przebudowa przy zmianie nazwy bazy - inaczej pierwsze wywołanie
        // zabetonowałoby połączenie na resztę procesu.
        if (config("database.connections.$name.database") !== $database) {
            $base = config('database.connections.' . config('database.default'), []);
            $base['database'] = $database;
            config(["database.connections.$name" => $base]);
            LaravelDb::purge($name);
        }

        return LaravelDb::connection($name);
    }

    public static function available(): bool
    {
        try {
            self::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Polecenie, które administrator bazy wykonuje raz przy instalacji. */
    public static function createDatabaseHint(): string
    {
        $db = Settings::database();
        $user = (string) (config('database.connections.' . config('database.default') . '.username') ?: 'librenms');

        return "sudo mysql -e \"CREATE DATABASE IF NOT EXISTS \\`$db\\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; "
            . "GRANT ALL PRIVILEGES ON \\`$db\\`.* TO '$user'@'localhost'; FLUSH PRIVILEGES;\"";
    }

    /**
     * Tworzy lub aktualizuje schemat rdzenia i włączonych modułów.
     * Tanie po pierwszym razie w procesie (jedno zapytanie o wersje).
     */
    public static function ensure(ModuleRegistry $registry, bool $force = false): void
    {
        if (self::$ensured && ! $force) {
            return;
        }

        $db = self::connection();

        try {
            $db->getPdo();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                Lang::t('Brak dostępu do bazy wtyczki `{db}`. Utwórz ją raz jako administrator bazy:', ['db' => Settings::database()]) . "\n  "
                . self::createDatabaseHint() . "\n" . Lang::t('Szczegóły: {error}', ['error' => $e->getMessage()])
            );
        }

        $schema = $db->getSchemaBuilder();

        if (! $schema->hasTable(self::SCHEMA)) {
            $schema->create(self::SCHEMA, function (Blueprint $t) {
                $t->string('module', 32)->primary();
                $t->unsignedInteger('version');
                $t->dateTime('updated_at');
            });
        }

        $versions = $db->table(self::SCHEMA)->pluck('version', 'module')->map(fn ($v) => (int) $v)->all();

        $core = $versions['core'] ?? 0;
        if ($core < self::CORE_VERSION) {
            self::migrateCore($db, $core);
            self::setVersion($db, 'core', self::CORE_VERSION);
        }

        // Migrujemy wszystkie zarejestrowane moduły, także wyłączone: włączenie
        // modułu nie powinno wymagać osobnej instalacji.
        foreach ($registry->all() as $module) {
            $have = $versions[$module->key()] ?? 0;
            if ($have < $module->schemaVersion()) {
                $module->migrate($db, $have);
                self::setVersion($db, $module->key(), $module->schemaVersion());
            }
        }

        self::$ensured = true;
    }

    /** @return array<string,int> */
    public static function versions(): array
    {
        try {
            return self::connection()->table(self::SCHEMA)->pluck('version', 'module')->map(fn ($v) => (int) $v)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private static function setVersion(Connection $db, string $module, int $version): void
    {
        $db->table(self::SCHEMA)->updateOrInsert(
            ['module' => $module],
            ['version' => $version, 'updated_at' => now()]
        );
    }

    private static function migrateCore(Connection $db, int $from): void
    {
        $schema = $db->getSchemaBuilder();

        if ($from < 1) {
            // Dziennik zmian. Wiersze są tylko dopisywane; UI nie ma edycji ani usuwania.
            if (! $schema->hasTable(self::AUDIT)) {
                $schema->create(self::AUDIT, function (Blueprint $t) {
                    $t->bigIncrements('id');
                    $t->dateTime('ts');
                    $t->string('module', 32);
                    $t->string('entity_type', 32);
                    $t->string('entity_key', 191);
                    $t->string('action', 32)->comment('create, update, delete, adopted, lnms_appeared, lnms_changed, lnms_gone, initial_import, settings');
                    $t->string('source', 8)->comment('user albo sync');
                    $t->unsignedInteger('user_id')->nullable();
                    $t->string('username', 191)->nullable();
                    $t->string('ip', 45)->nullable();
                    $t->longText('before')->nullable()->comment('JSON');
                    $t->longText('after')->nullable()->comment('JSON');
                    $t->text('comment')->nullable();
                    $t->index('ts');
                    $t->index(['module', 'entity_type', 'entity_key']);
                    $t->index(['module', 'action']);
                });
            }

            // Migawka ostatniego stanu LibreNMS, per moduł i typ rekordu.
            if (! $schema->hasTable(self::SNAPSHOT)) {
                $schema->create(self::SNAPSHOT, function (Blueprint $t) {
                    $t->bigIncrements('id');
                    $t->string('module', 32);
                    $t->string('entity_type', 32);
                    $t->string('entity_key', 191);
                    $t->char('hash', 40);
                    $t->longText('data')->comment('JSON');
                    $t->dateTime('first_seen');
                    $t->dateTime('last_seen');
                    $t->dateTime('gone_at')->nullable();
                    $t->unique(['module', 'entity_type', 'entity_key'], 'reg_snapshot_key');
                    $t->index(['module', 'entity_type', 'gone_at']);
                });
            }

            // Drobny magazyn klucz-wartość: czasy synchronizacji, podsumowania.
            if (! $schema->hasTable(self::STATE)) {
                $schema->create(self::STATE, function (Blueprint $t) {
                    $t->string('k', 191)->primary();
                    $t->longText('v')->nullable();
                    $t->dateTime('updated_at');
                });
            }
        }

        if ($from < 2) {
            // Zakres liczbowy, którego dotyczy wpis (np. VLAN-y 1313-1399 dodane
            // jednym kliknięciem = jeden wpis). Historia pojedynczego numeru
            // znajduje wtedy wpis grupowy po range_from <= n <= range_to.
            if (! $schema->hasColumn(self::AUDIT, 'range_from')) {
                $schema->table(self::AUDIT, function (Blueprint $t) {
                    $t->unsignedInteger('range_from')->nullable()->after('entity_key');
                    $t->unsignedInteger('range_to')->nullable()->after('range_from');
                    $t->index(['module', 'range_from', 'range_to']);
                });
            }
        }
    }

    public static function state(string $key): mixed
    {
        try {
            $v = self::connection()->table(self::STATE)->where('k', $key)->value('v');

            return $v === null ? null : json_decode((string) $v, true);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function setState(string $key, mixed $value): void
    {
        self::connection()->table(self::STATE)->updateOrInsert(
            ['k' => $key],
            ['v' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]
        );
    }
}
