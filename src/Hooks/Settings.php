<?php

/*
 * Settings.php - panel administracyjny /plugin/settings/Netella-Reg.
 *
 * Rdzeń nie daje formularza: widok sam wysyła POST na plugin.update
 * z plugin_active=1 i polami settings[...]. LibreNMS zastępuje wtedy cały
 * JSON ustawień, więc formularz musi zawierać wszystkie pola.
 *
 * Po zapisie LibreNMS wraca na tę stronę - wtedy SettingsWatcher zapisuje
 * zmianę w dzienniku z nazwą administratora.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Hooks;

use Ispnetella\NetellaReg\Core\Access;
use Ispnetella\NetellaReg\Core\Backup;
use Ispnetella\NetellaReg\Core\Db;
use Ispnetella\NetellaReg\Core\Lang;
use Ispnetella\NetellaReg\Core\ModuleRegistry;
use Ispnetella\NetellaReg\Core\Settings as RegSettings;
use Ispnetella\NetellaReg\Core\SettingsWatcher;
use Ispnetella\NetellaReg\Core\SyncRunner;
use Ispnetella\NetellaReg\Plugin;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;

class Settings implements SettingsHook
{
    public function authorize(...$args): bool
    {
        return true; // trasę i tak chroni can:plugin.admin
    }

    public function handle(string $pluginName, ...$args): array
    {
        $registry = app(ModuleRegistry::class);
        $dbOk = false;
        $dbError = null;

        try {
            Db::ensure($registry);
            $dbOk = true;
            SettingsWatcher::check(true);
        } catch (\Throwable $e) {
            $dbError = $e->getMessage();
        }

        $modules = [];
        foreach ($registry->all() as $key => $m) {
            $modules[$key] = [
                'name' => $m->name(),
                'enabled' => RegSettings::moduleEnabled($key),
                'sync' => $dbOk ? SyncRunner::last($key) : null,
            ];
        }

        return [
            'content_view' => Plugin::NAME . '::settings',
            'settings' => RegSettings::all(),
            'nregInfo' => [
                'version' => Plugin::VERSION,
                'database' => RegSettings::database(),
                'dbOk' => $dbOk,
                'dbError' => $dbError,
                'dbHint' => Db::createDatabaseHint(),
                'versions' => $dbOk ? Db::versions() : [],
                'modules' => $modules,
                'roles' => Access::availableRoles(),
                'levels' => [
                    'perm_view_roles' => Lang::t('Przeglądanie'),
                    'perm_edit_roles' => Lang::t('Edycja'),
                    'perm_delete_roles' => Lang::t('Usuwanie'),
                    'perm_admin_roles' => Lang::t('Administracja'),
                ],
                'pluginName' => Plugin::NAME,
                'backups' => self::backups(),
                'backupDir' => Backup::dir(),
                'settingsBackup' => is_file(Backup::settingsPath()) ? Backup::settingsPath() : null,
                'apiUrl' => url('plugin/' . Plugin::NAME . '/api'),
                'cron' => '*/5 * * * * librenms /usr/bin/env php /opt/librenms/lnms netella-reg:sync -q >> /opt/librenms/logs/' . Plugin::LOG_FILE . ' 2>&1',
            ],
        ];
    }

    private static function backups(): array
    {
        try {
            return array_map(fn ($b) => $b + ['url' => url('plugin/' . Plugin::NAME . '/backup/' . $b['file'])], Backup::list());
        } catch (\Throwable) {
            return [];
        }
    }
}
