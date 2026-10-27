<?php

/*
 * Plugin.php - stałe wtyczki: nazwa, wersja, domyślna baza.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg;

final class Plugin
{
    /** Wersja wtyczki - jedyne źródło prawdy. Podbijaj przy każdej zmianie funkcjonalnej. */
    public const VERSION = '0.2.1';

    /** Nazwa wtyczki w LibreNMS (tabela plugins, adres /plugin/Netella-Reg). */
    public const NAME = 'Netella-Reg';

    /** Własna baza obok bazy LibreNMS. */
    public const DEFAULT_DATABASE = 'librenms_netella_reg';

    /** Nazwa połączenia Laravela do bazy wtyczki. */
    public const CONNECTION = 'netella_reg';

    /** Logi crona i synchronizacji. */
    public const LOG_FILE = 'netella-reg.log';
}
