<?php

/*
 * DeviceOverview.php - panel na stronie Overview urządzenia: tylko link do wtyczki
 * (od 0.6.3 bez list VLAN-ów i adresów).
 *
 * Od rdzenia 05de175 (2026-08) Overview renderuje hooki przez {{ }}, więc
 * handle() musi zwracać HtmlString - zwykły string pokazałby surowy HTML.
 * Każdy wyjątek łapiemy, bo LibreNMS wyłączyłby wtyczkę. Argumenty bierzemy
 * przez ...$args i wybieramy po typie, bo kolejność różni się między wersjami.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Hooks;

use Illuminate\Support\HtmlString;
use Ispnetella\NetellaReg\Core\Access;
use Ispnetella\NetellaReg\Plugin;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;

class DeviceOverview implements DeviceOverviewHook
{
    public function authorize(...$args): bool
    {
        return true;
    }

    public function handle(string $pluginName, ...$args): HtmlString
    {
        try {
            if (! Access::canView()) {
                return new HtmlString('');
            }

            return new HtmlString(view(Plugin::NAME . '::device-overview', [
                'pageUrl' => url('plugin/' . Plugin::NAME),
            ])->render());
        } catch (\Throwable) {
            return new HtmlString('');
        }
    }
}
