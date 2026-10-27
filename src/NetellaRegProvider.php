<?php

/*
 * NetellaRegProvider.php - service provider pakietu.
 *
 * Rejestruje rejestr modułów z modułami wbudowanymi, hooki LibreNMS,
 * komendy lnms, trasy API i widoki.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg;

use Illuminate\Support\ServiceProvider;
use Ispnetella\NetellaReg\Console\DoctorCommand;
use Ispnetella\NetellaReg\Console\InstallCommand;
use Ispnetella\NetellaReg\Console\SyncCommand;
use Ispnetella\NetellaReg\Core\ModuleRegistry;
use Ispnetella\NetellaReg\Hooks\DeviceOverview;
use Ispnetella\NetellaReg\Hooks\MenuEntry;
use Ispnetella\NetellaReg\Hooks\Page;
use Ispnetella\NetellaReg\Hooks\Settings;
use Ispnetella\NetellaReg\Modules\Ipam\IpamModule;
use Ispnetella\NetellaReg\Modules\Vlan\VlanModule;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

class NetellaRegProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class, function () {
            $registry = new ModuleRegistry();
            // Moduły wbudowane. Kolejność = kolejność zakładek.
            $registry->register(new VlanModule());
            $registry->register(new IpamModule());

            return $registry;
        });
    }

    public function boot(PluginManagerInterface $pluginManager): void
    {
        $name = Plugin::NAME;

        $pluginManager->publishHook($name, MenuEntryHook::class, MenuEntry::class);
        $pluginManager->publishHook($name, SettingsHook::class, Settings::class);
        $pluginManager->publishHook($name, SinglePageHook::class, Page::class);
        $pluginManager->publishHook($name, DeviceOverviewHook::class, DeviceOverview::class);

        // Komendy są dostępne także przy wyłączonej wtyczce - install i doctor
        // mają działać przed pierwszym włączeniem.
        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, SyncCommand::class, DoctorCommand::class]);
        }

        // Widoki ładujemy zawsze: strona ustawień jest dostępna dla wyłączonej
        // wtyczki w panelu Plugin Admin, a jej widok musi istnieć.
        $this->loadViewsFrom(__DIR__ . '/../resources/views', $name);

        if (! $pluginManager->pluginEnabled($name)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
    }
}
