<?php

/*
 * VlanIndex.php - status każdego numeru 1-4094 w całej sieci.
 *
 * Wejście: wiersze migawki (jeden na parę urządzenie + VLAN). Numer jest
 * zajęty, jeśli występuje na którymkolwiek urządzeniu. Priorytet statusów:
 *
 *   l  LibreNMS      jest na aktywnym urządzeniu
 *   s  nieaktualny   jest tylko na urządzeniach wyłączonych w LibreNMS
 *                    (gdy ustawienie disabled_devices = ignore - wolny)
 *   g  zniknął       zniknął z LibreNMS i trwa karencja (grace_days)
 *   f  wolny
 *
 * Statusy wpisów ręcznych (m, r) dojdą w 0.2.
 *
 * Czyste, bez Laravela.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Modules\Vlan;

final class VlanIndex
{
    public const FREE = 'f';
    public const LNMS = 'l';
    public const STALE = 's';
    public const GONE = 'g';

    public const LABELS = [
        self::FREE => 'wolny',
        self::LNMS => 'LibreNMS',
        self::STALE => 'nieaktualny (urządzenie wyłączone)',
        self::GONE => 'zniknął z LibreNMS (karencja)',
    ];

    /** Większa liczba wygrywa przy kilku wystąpieniach. */
    private const RANK = [self::FREE => 0, self::GONE => 1, self::STALE => 2, self::LNMS => 3];

    /** @var array<int,string> numer => status */
    private array $status = [];

    /** @var array<int,list<array>> numer => wystąpienia */
    private array $occurrences = [];

    /**
     * @param  list<array{key:string,data:array,first_seen:string,last_seen:string,gone_at:?string}>  $rows
     */
    public function __construct(array $rows, \DateTimeImmutable $now, int $graceDays, string $disabledMode)
    {
        $graceLimit = $now->modify("-{$graceDays} days")->format('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $vlan = (int) ($row['data']['vlan'] ?? 0);
            if ($vlan < VlanName::MIN || $vlan > VlanName::MAX) {
                continue;
            }

            $state = self::rowState($row, $graceLimit, $disabledMode);
            $row['state'] = $state;
            $this->occurrences[$vlan][] = $row;

            if ($state !== self::FREE
                && self::RANK[$state] > self::RANK[$this->status[$vlan] ?? self::FREE]) {
                $this->status[$vlan] = $state;
            }
        }
    }

    private static function rowState(array $row, string $graceLimit, string $disabledMode): string
    {
        if ($row['gone_at'] !== null) {
            return $row['gone_at'] >= $graceLimit ? self::GONE : self::FREE;
        }

        if (! empty($row['data']['disabled'])) {
            return $disabledMode === 'ignore' ? self::FREE : self::STALE;
        }

        return self::LNMS;
    }

    public function status(int $vlan): string
    {
        return $this->status[$vlan] ?? self::FREE;
    }

    public function isFree(int $vlan): bool
    {
        return $this->status($vlan) === self::FREE;
    }

    /** Wszystkie wystąpienia numeru, także historyczne (po karencji mają state = f). */
    public function occurrences(int $vlan): array
    {
        return $this->occurrences[$vlan] ?? [];
    }

    /** 4094 znaki, znak i-ty to status VLAN-u i+1. Zwięzłe do przesłania mapy. */
    public function statusString(): string
    {
        $s = '';
        for ($v = VlanName::MIN; $v <= VlanName::MAX; $v++) {
            $s .= $this->status($v);
        }

        return $s;
    }

    /** @return array<string,int> status => liczba numerów */
    public function counts(): array
    {
        $c = array_fill_keys(array_keys(self::LABELS), 0);
        for ($v = VlanName::MIN; $v <= VlanName::MAX; $v++) {
            $c[$this->status($v)]++;
        }

        return $c;
    }
}
