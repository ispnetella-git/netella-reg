<?php

/*
 * Page.php - główna strona wtyczki /plugin/Netella-Reg.
 *
 * Zakładki: po jednej na włączony moduł plus Dziennik. Dane zakładek ładuje
 * JS z API (routes/web.php), więc strona renderuje się szybko także przy
 * dużej liczbie rekordów.
 *
 * Klucze zwracane z handle() mają prefiks "nreg", bo LibreNMS renderuje
 * widok przez @include($content_view, $settings) i klucze ustawień
 * przykryłyby zmienne o tej samej nazwie (pułapka z Netella-ZTE 0.28).
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Hooks;

use Illuminate\Support\Facades\Log;
use Ispnetella\NetellaReg\Core\Access;
use Ispnetella\NetellaReg\Core\Db;
use Ispnetella\NetellaReg\Core\ModuleRegistry;
use Ispnetella\NetellaReg\Core\SyncRunner;
use Ispnetella\NetellaReg\Plugin;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;

class Page implements SinglePageHook
{
    /** authorize() nie dostaje zalogowanego użytkownika - patrz Core\Access. */
    public function authorize(...$args): bool
    {
        return Access::canView();
    }

    public function handle(string $pluginName, ...$args): array
    {
        $registry = app(ModuleRegistry::class);
        $error = null;
        $tabs = [];

        try {
            Db::ensure($registry);

            foreach ($registry->enabled() as $key => $module) {
                $view = $module->tabView();
                if ($view === null) {
                    continue;
                }
                $tabs[] = [
                    'key' => $key,
                    'name' => $module->name(),
                    'view' => str_contains($view, '::') ? $view : Plugin::NAME . '::' . $view,
                    'data' => $module->tabData(),
                    'sync' => SyncRunner::last($key),
                ];
            }
        } catch (\Throwable $e) {
            // Wyjątek z hooka wyłącza wtyczkę w LibreNMS - pokazujemy komunikat.
            Log::error('Netella-Reg: ' . $e->getMessage());
            $error = $e->getMessage();
        }

        return [
            'title' => Plugin::NAME,
            'content_view' => Plugin::NAME . '::page',
            'nregTabs' => $tabs,
            'nregError' => $error,
            'nregBoot' => [
                'api' => url('plugin/' . Plugin::NAME . '/api'),
                // Pełny adres strony: LibreNMS ustawia <base href>, więc względne "?vlan=" trafia na stronę główną.
                'page' => url('plugin/' . Plugin::NAME),
                'csrf' => csrf_token(),
                'version' => Plugin::VERSION,
                'canEdit' => Access::canEdit(),
                'canDelete' => Access::canDelete(),
                'canAdmin' => Access::canAdmin(),
                'modules' => array_map(fn ($t) => ['key' => $t['key'], 'name' => $t['name']], $tabs),
                'deviceUrl' => url('device'),
                // 'stale': adresy wyłączonych hostów są w rejestrze (przełącznik w adresacji), 'ignore': nie ma ich wcale.
                'disabledMode' => \Ispnetella\NetellaReg\Core\Settings::disabledMode(),
            ],
        ];
    }
}
