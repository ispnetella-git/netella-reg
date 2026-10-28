<?php

/*
 * RegModule.php - kontrakt modułu Netella-Reg.
 *
 * Moduł to samodzielna część wtyczki z własnymi tabelami, synchronizacją
 * z LibreNMS, API i zakładką. Rdzeń nic o nim nie wie poza tym kontraktem,
 * więc nowy moduł (także z osobnego pakietu composera) dokłada się przez
 * ModuleRegistry::register() bez zmian w rdzeniu.
 *
 * Wspólne wartości domyślne daje Modules\AbstractModule.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Contracts;

use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Ispnetella\NetellaReg\Core\SyncContext;

interface RegModule
{
    /** Krótki klucz [a-z0-9_]: prefiks tabel, ustawień i adresów API. */
    public function key(): string;

    /** Nazwa zakładki. */
    public function name(): string;

    /** Wersja schematu modułu. Podbicie uruchamia migrate() z poprzednią wersją. */
    public function schemaVersion(): int;

    /** Tworzy lub aktualizuje tabele modułu od wersji $from (0 = nowa instalacja). */
    public function migrate(Connection $db, int $from): void;

    /**
     * Odczyt LibreNMS, porównanie z migawką, wpisy do dziennika.
     *
     * @return array<string,int|string> krótkie podsumowanie do logu i ustawień
     */
    public function sync(SyncContext $ctx): array;

    /** Widok blade zakładki (np. "Netella-Reg::modules.vlan.tab") albo null. */
    public function tabView(): ?string;

    /** Dane dla widoku zakładki, liczone przy renderowaniu strony. */
    public function tabData(): array;

    /**
     * Obsługa /plugin/Netella-Reg/api/<key>/<action>. Zwraca dane do JSON-a.
     * Rzuca Core\ApiException z kodem HTTP przy błędzie.
     */
    public function api(string $action, Request $request): array;

    /** Prawo wymagane do akcji API (Access::VIEW, EDIT, DELETE, ADMIN). */
    public function apiPermission(string $action, string $method): string;

}
