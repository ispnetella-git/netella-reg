<?php

/*
 * Settings.php - ustawienia wtyczki zapisane przez LibreNMS w plugins.settings.
 *
 * Rdzeń LibreNMS wywołuje hooki z PUSTĄ tablicą ustawień, więc czytamy je
 * sami z tabeli plugins. Formularz ustawień wysyła zawsze komplet pól, a
 * kontroler LibreNMS zastępuje nimi cały JSON, więc brakujący klucz znaczy
 * "nigdy nie zapisano" i dostaje wartość domyślną.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Core;

use Ispnetella\NetellaReg\Plugin;

final class Settings
{
    public const DEFAULTS = [
        // Nazwa bazy wtyczki; pusta = Plugin::DEFAULT_DATABASE.
        'db_name' => '',
        // Role LibreNMS z prawem do danej czynności. Admin ma zawsze wszystko.
        // Pusta lista: view = każdy zalogowany, pozostałe = tylko admin.
        'perm_view_roles' => [],
        'perm_edit_roles' => [],
        'perm_delete_roles' => [],
        'perm_admin_roles' => [],
        // Ile dni rekord, który zniknął z LibreNMS, liczy się jeszcze jako zajęty.
        'grace_days' => 30,
        // Rekordy z urządzeń wyłączonych w LibreNMS: 'stale' = zajęte, ale
        // oznaczone jako nieaktualne; 'ignore' = traktowane jak wolne.
        'disabled_devices' => 'stale',
        // Adresacja: zakresy IPv4 pomijane przy synchronizacji (loopback, link-local, "this network").
        'ipam_ignore' => '127.0.0.0/8, 169.254.0.0/16, 0.0.0.0/8',
        // Adresacja: interfejsy pomijane (regex, bez ograniczników). Domyślnie wewnętrzne
        // interfejsy Junipera emN.x i jsrv z adresami 128.0.0.x/2.
        'ipam_ignore_if' => '^(jsrv|em[0-9]+\.)',
        // Adresacja: poziomy grupowania drzewa (maski węzłów wirtualnych).
        'ipam_levels' => '8,16,24',
        // Włączenie modułów: module_<klucz> => '1' / '0'. Brak klucza = włączony.
    ];

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    /** @return array<string,mixed> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $stored = [];

        try {
            $json = \Illuminate\Support\Facades\DB::table('plugins')
                ->where('plugin_name', Plugin::NAME)
                ->orderByDesc('plugin_active')
                ->value('settings');
            $stored = is_string($json) ? (json_decode($json, true) ?: []) : (is_array($json) ? $json : []);
        } catch (\Throwable) {
            // tabela plugins niedostępna - zostają wartości domyślne
        }

        return self::$cache = array_merge(self::DEFAULTS, $stored);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    /** Do testów i po zapisie ustawień w tym samym żądaniu. */
    public static function reset(?array $values = null): void
    {
        self::$cache = $values === null ? null : array_merge(self::DEFAULTS, $values);
    }

    public static function database(): string
    {
        $name = trim((string) self::get('db_name', ''));

        // Tylko bezpieczne znaki - nazwa trafia do komunikatu z poleceniem SQL.
        return preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) ? $name : Plugin::DEFAULT_DATABASE;
    }

    public static function graceDays(): int
    {
        return max(0, min(3650, (int) self::get('grace_days', 30)));
    }

    public static function disabledMode(): string
    {
        return self::get('disabled_devices') === 'ignore' ? 'ignore' : 'stale';
    }

    public static function moduleEnabled(string $key): bool
    {
        return (string) self::get('module_' . $key, '1') !== '0';
    }

    /**
     * Lista ról z ustawień. Formularz zapisuje tablicę, ale akceptujemy też
     * napis z przecinkami (ręczna edycja JSON-a, starsze wersje).
     *
     * @return list<string>
     */
    public static function roles(string $key): array
    {
        $value = self::get($key, []);

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($r) => trim((string) $r), $value),
            static fn (string $r) => $r !== ''
        )));
    }
}
