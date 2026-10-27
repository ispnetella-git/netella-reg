<?php

/*
 * AbstractModule.php - wartości domyślne kontraktu RegModule.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules;

use Illuminate\Http\Request;
use Ispnetella\NetellaReg\Contracts\RegModule;
use Ispnetella\NetellaReg\Core\Access;
use Ispnetella\NetellaReg\Core\ApiException;

abstract class AbstractModule implements RegModule
{
    public function tabView(): ?string
    {
        return null;
    }

    public function tabData(): array
    {
        return [];
    }

    public function api(string $action, Request $request): array
    {
        throw new ApiException("Nieznana akcja '$action'", 404);
    }

    /** Domyślnie odczyt wymaga view, a każda zmiana edit. */
    public function apiPermission(string $action, string $method): string
    {
        return strtoupper($method) === 'GET' ? Access::VIEW : Access::EDIT;
    }

    public function deviceOverview(int $deviceId): ?array
    {
        return null;
    }
}
