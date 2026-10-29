<?php

/*
 * BackupController.php - pobranie pliku kopii zapasowej (tylko administrator wtyczki).
 *
 *   GET /plugin/Netella-Reg/backup/<plik>
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Http;

use Illuminate\Routing\Controller;
use Ispnetella\NetellaReg\Core\Access;
use Ispnetella\NetellaReg\Core\Backup;
use Ispnetella\NetellaReg\Core\Lang;
use Symfony\Component\HttpFoundation\Response;

final class BackupController extends Controller
{
    public function __invoke(string $file): Response
    {
        abort_unless(Access::canAdmin(), 403, Lang::t('Kopie zapasowe może pobrać tylko administrator wtyczki.'));

        $path = Backup::path($file);
        abort_if($path === null, 404);

        return response()->download($path, $file, ['Content-Type' => 'application/gzip']);
    }
}
