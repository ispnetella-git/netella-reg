<?php

/*
 * ApiException.php - błąd zwracany przez API z kodem HTTP i komunikatem dla użytkownika.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Core;

final class ApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}
