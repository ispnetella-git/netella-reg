<?php

/*
 * SyncContext.php - to, co rdzeń daje modułowi na czas jednej synchronizacji.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Core;

use Illuminate\Database\Connection;

final class SyncContext
{
    /** @var callable(string):void */
    private $logger;

    public function __construct(
        public readonly \DateTimeImmutable $now,
        public readonly Connection $db,
        public readonly Snapshot $snapshot,
        ?callable $logger = null,
    ) {
        $this->logger = $logger ?? static function (string $m): void {
        };
    }

    public function log(string $message): void
    {
        ($this->logger)($message);
    }
}
