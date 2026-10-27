<?php

/*
 * ModuleRegistry.php - lista modułów wtyczki.
 *
 * Moduły wbudowane rejestruje NetellaRegProvider. Moduł z innego pakietu
 * composera rejestruje się w swoim providerze:
 *
 *   $this->app->afterResolving(ModuleRegistry::class, fn ($r) => $r->register(new MojModul));
 *
 * albo wprost app(ModuleRegistry::class)->register(new MojModul) w boot().
 * API i zakładki rozwiązują moduł dopiero przy żądaniu, więc kolejność
 * ładowania providerów nie ma znaczenia.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Core;

use Ispnetella\NetellaReg\Contracts\RegModule;

final class ModuleRegistry
{
    /** @var array<string,RegModule> */
    private array $modules = [];

    public function register(RegModule $module): void
    {
        $key = $module->key();

        if (! preg_match('/^[a-z][a-z0-9_]{1,31}$/', $key) || $key === 'core') {
            throw new \InvalidArgumentException("Netella-Reg: niepoprawny klucz modułu '$key'");
        }

        $this->modules[$key] = $module;
    }

    /** @return array<string,RegModule> */
    public function all(): array
    {
        return $this->modules;
    }

    /** @return array<string,RegModule> */
    public function enabled(): array
    {
        return array_filter($this->modules, static fn (RegModule $m) => Settings::moduleEnabled($m->key()));
    }

    public function get(string $key): ?RegModule
    {
        return $this->modules[$key] ?? null;
    }

    public function enabledModule(string $key): ?RegModule
    {
        $m = $this->get($key);

        return $m !== null && Settings::moduleEnabled($key) ? $m : null;
    }
}
