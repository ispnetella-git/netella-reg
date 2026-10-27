<?php

/*
 * MenuEntry.php - wpis w menu Plugins. Ukryty dla osób bez prawa podglądu,
 * bo prowadziłby na stronę "Missing view".
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Hooks;

use Ispnetella\NetellaReg\Core\Access;
use Ispnetella\NetellaReg\Plugin;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;

class MenuEntry implements MenuEntryHook
{
    public function authorize(...$args): bool
    {
        return Access::canView();
    }

    public function handle(string $pluginName, ...$args): array
    {
        return [Plugin::NAME . '::menu', []];
    }
}
