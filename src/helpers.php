<?php

/*
 * helpers.php - skrót do tłumaczeń w widokach Blade: {{ nreg_t("Zapisz") }}.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

use Ispnetella\NetellaReg\Core\Lang;

if (! function_exists('nreg_t')) {
    function nreg_t(string $pl, array $vars = []): string
    {
        return Lang::t($pl, $vars);
    }
}
